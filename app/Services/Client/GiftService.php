<?php

namespace App\Services\Client;

use App\Exceptions\BusinessException;
use App\Models\GiftClaim;
use App\Models\GiftEnvelope;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Compliance\FraudService;
use App\Services\Compliance\LimitService;
use App\Services\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\TransactionNotifier;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enveloppes rouges / cadeaux d'argent (§3.5.1), inspirées du hongbao WeChat Pay :
 *   fixed  : montant fixe pour chaque destinataire désigné
 *   random : cagnotte répartie aléatoirement entre les N premiers qui l'ouvrent
 * Fonds logés sur flashpay:gifts jusqu'à récupération ; non réclamés à
 * l'expiration → recrédités à l'expéditeur. Mêmes plafonds / contrôles que le P2P.
 */
class GiftService
{
    public const ACCOUNT = 'flashpay:gifts';

    public function __construct(
        protected WalletService $wallets,
        protected LedgerService $ledger,
        protected NotificationService $notify,
        protected PeexFlowService $flows,
        protected PeexCorridors $corridors,
        protected LimitService $limits,
        protected FraudService $fraud,
    ) {
    }

    /** Contrôles avant d'encaisser un cadeau payé par mobile money / carte. */
    public function precheck(User $sender, array $d): void
    {
        if (($d['mode'] ?? '') === 'fixed') {
            $phones = collect($d['recipients'] ?? [])->map(fn ($p) => $this->corridors->resolve((string) $p)['phone'])->unique();
            if ($phones->isEmpty()) {
                throw new BusinessException('Ajoutez au moins un destinataire.', 'no_recipient');
            }
        } elseif ((int) $d['amount'] < (int) ($d['shares'] ?? 1) * 50) {
            throw new BusinessException('Montant trop faible pour ' . (int) $d['shares'] . ' parts (50 minimum par part).', 'amount_too_low');
        }
    }

    /**
     * Recharge « cadeau » confirmée (mobile money / carte) : le montant est sur le
     * wallet, on crée maintenant le cadeau. En cas d'échec, l'argent reste sur le
     * wallet et l'expéditeur est prévenu.
     */
    public function completeFunding(Transaction $tx): void
    {
        $meta = $tx->meta ?? [];
        if (empty($meta['pending_gift']) || ! empty($meta['gift_code']) || ! empty($meta['gift_error'])) {
            return;
        }
        $lock = \Illuminate\Support\Facades\Cache::lock('gift-funding:' . $tx->id, 30);
        if (! $lock->get()) {
            return;
        }
        try {
            $tx->refresh();
            $meta = $tx->meta ?? [];
            if (! empty($meta['gift_code']) || ! empty($meta['gift_error'])) {
                return;
            }
            $sender = User::find($tx->initiated_by);
            try {
                $env = $this->create($sender, $meta['pending_gift']);
                $tx->forceFill(['meta' => $meta + ['gift_code' => $env->code]])->saveQuietly();
                $this->notify->toUser($sender, 'gift_sent', 'Cadeau envoyé 🧧', 'Paiement reçu : votre cadeau est parti. Code ' . $env->code . ' — lien ' . url('/g/' . $env->code), ['severity' => 'success', 'data' => ['code' => $env->code]]);
            } catch (\Throwable $e) {
                $tx->forceFill(['meta' => $meta + ['gift_error' => mb_substr($e->getMessage(), 0, 200)]])->saveQuietly();
                $this->notify->toUser($sender, 'gift_failed', 'Cadeau non envoyé', 'Le montant a bien été crédité sur votre wallet, mais le cadeau n\'a pas pu partir : ' . $e->getMessage(), ['severity' => 'warning']);
            }
        } finally {
            $lock->release();
        }
    }

    public function create(User $sender, array $d): GiftEnvelope
    {
        $wallet = $this->flows->walletOf($sender);
        $mode = $d['mode'];
        $phones = collect();
        if ($mode === 'fixed') {
            $phones = collect($d['recipients'] ?? [])->map(fn ($p) => $this->corridors->resolve((string) $p)['phone'])->unique()->values();
            if ($phones->isEmpty()) {
                throw new BusinessException('Ajoutez au moins un destinataire.', 'no_recipient');
            }
            $shares = $phones->count();
            $total = (int) $d['amount'] * $shares;
        } else {
            $shares = (int) ($d['shares'] ?? 1);
            $total = (int) $d['amount'];
            if ($total < $shares * 50) {
                throw new BusinessException('Montant trop faible pour ' . $shares . ' parts (50 minimum par part).', 'amount_too_low');
            }
        }

        $this->fraud->evaluate($sender, $total, 'gift', $wallet->currency);
        $this->limits->assertOutgoing($sender, $wallet, $total, 'national', 'gift');

        return DB::transaction(function () use ($sender, $wallet, $mode, $shares, $total, $d, $phones) {
            $this->wallets->debit($wallet, $total);
            $tx = Transaction::create([
                'reference' => 'FP-' . Str::upper(Str::random(12)),
                'type' => 'gift', 'scope' => 'national',
                'source_rail' => 'wallet', 'destination_rail' => 'wallet',
                'source_wallet_id' => $wallet->id,
                'amount' => $total, 'currency' => $wallet->currency,
                'status' => 'successful', 'completed_at' => now(),
                'initiated_by' => $sender->id,
                'meta' => array_filter(['channel' => 'gift', 'mode' => $mode, 'shares' => $shares, 'message' => $d['message'] ?? null, 'occasion' => $d['occasion'] ?? null]),
            ]);
            $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", self::ACCOUNT, $total, null, 'Cadeau d\'argent');

            $env = GiftEnvelope::create([
                'code' => strtoupper(Str::random(8)),
                'sender_id' => $sender->id, 'wallet_id' => $wallet->id,
                'mode' => $mode, 'total_amount' => $total, 'remaining_amount' => $total,
                'shares' => $shares, 'currency' => $wallet->currency,
                'message' => $d['message'] ?? null, 'occasion' => $d['occasion'] ?? null,
                'status' => 'active', 'claimed_shares' => 0, 'reminder_sent' => false,
                'expires_at' => now()->addHours((int) ($d['expires_in_hours'] ?? 24)),
                'transaction_id' => $tx->id,
            ]);

            if ($mode === 'fixed') {
                foreach ($phones as $phone) {
                    $u = $this->flows->findUserByPhone($phone);
                    GiftClaim::create(['gift_envelope_id' => $env->id, 'recipient_id' => $u?->id, 'recipient_phone' => $phone, 'amount' => (int) $d['amount']]);
                    $title = '🧧 ' . $sender->full_name . ' vous envoie un cadeau';
                    $u ? $this->notify->toUser($u, 'gift_received', $title, $env->message ?: 'Ouvrez l\'app pour le récupérer avant expiration.', ['data' => ['gift_code' => $env->code]])
                       : $this->notify->sms($phone, "FlashPay: {$sender->full_name} vous envoie un cadeau d'argent. Installez FlashPay et utilisez le code {$env->code} avant 24h.");
                }
            }
            return $env->load('claims');
        });
    }

    public function claim(User $user, string $code): GiftClaim
    {
        return DB::transaction(function () use ($user, $code) {
            $env = GiftEnvelope::where('code', strtoupper($code))->lockForUpdate()->first();
            if (! $env || $env->status !== 'active' || $env->expires_at->isPast()) {
                throw new BusinessException('Cadeau introuvable, déjà entièrement récupéré ou expiré.', 'gift_unavailable');
            }
            if ($env->sender_id === $user->id) {
                throw new BusinessException('Vous ne pouvez pas ouvrir votre propre cadeau.', 'own_gift');
            }
            $wallet = $this->flows->walletOf($user);
            if ($wallet->currency !== $env->currency) {
                throw new BusinessException('Devise du cadeau différente de celle de votre wallet.', 'currency');
            }

            if ($env->mode === 'fixed') {
                $claim = GiftClaim::where('gift_envelope_id', $env->id)->whereNull('claimed_at')
                    ->where(fn ($q) => $q->where('recipient_id', $user->id)->orWhereIn('recipient_phone', [$user->phone, '+' . ltrim($user->phone, '+'), ltrim($user->phone, '+')]))
                    ->lockForUpdate()->first();
                if (! $claim) {
                    throw new BusinessException('Ce cadeau ne vous est pas destiné ou a déjà été récupéré.', 'not_recipient');
                }
                $amount = $claim->amount;
            } else {
                if (GiftClaim::where('gift_envelope_id', $env->id)->where('recipient_id', $user->id)->exists()) {
                    throw new BusinessException('Vous avez déjà ouvert cette enveloppe.', 'already_claimed');
                }
                $left = $env->shares - $env->claimed_shares;
                $amount = $left <= 1 ? $env->remaining_amount : $this->randomShare($env->remaining_amount, $left);
                $claim = GiftClaim::create(['gift_envelope_id' => $env->id, 'recipient_id' => $user->id, 'recipient_phone' => $user->phone, 'amount' => $amount]);
            }

            $this->limits->assertIncoming($wallet, $amount, 'gift_claim');
            $tx = Transaction::create([
                'reference' => 'FP-' . Str::upper(Str::random(12)),
                'type' => 'gift_claim', 'scope' => 'national',
                'source_rail' => 'wallet', 'destination_rail' => 'wallet',
                'destination_wallet_id' => $wallet->id,
                'amount' => $amount, 'currency' => $env->currency,
                'status' => 'successful', 'completed_at' => now(),
                'initiated_by' => $user->id,
                'meta' => array_filter(['channel' => 'gift', 'gift_code' => $env->code, 'sender_name' => $env->sender->full_name, 'note' => $env->message]),
            ]);
            $this->wallets->credit($wallet, $amount);
            $this->ledger->recordDoubleEntry($tx, self::ACCOUNT, "wallet:{$wallet->id}", $amount, null, 'Cadeau récupéré');

            $claim->update(['recipient_id' => $user->id, 'claimed_at' => now(), 'transaction_id' => $tx->id]);
            $env->update([
                'remaining_amount' => $env->remaining_amount - $amount,
                'claimed_shares' => $env->claimed_shares + 1,
                'status' => $env->claimed_shares + 1 >= $env->shares ? 'completed' : 'active',
            ]);

            app(TransactionNotifier::class)->handle($tx);
            $this->notify->toUser($env->sender, 'gift_claimed', "{$user->full_name} a récupéré votre cadeau", number_format($amount, 0, ',', ' ') . " {$env->currency}", ['sms' => false]);

            return $claim->fresh();
        });
    }

    /** Montant aléatoire « enveloppe rouge » : entre 1 et 2× la moyenne restante. */
    protected function randomShare(int $remaining, int $left): int
    {
        $max = max(1, intdiv(2 * $remaining, $left) - 1);
        return max(1, min($remaining - ($left - 1), random_int(1, $max)));
    }

    /** Expire les enveloppes échues et recrédite l'expéditeur (planifié). */
    public function expireDue(): int
    {
        $n = 0;
        GiftEnvelope::where('status', 'active')->where('expires_at', '<', now())->each(function (GiftEnvelope $env) use (&$n) {
            $n += $this->closeEnvelope($env, 'expired') ? 1 : 0;
        });

        // Rappel 2 h avant expiration aux destinataires qui n'ont pas récupéré (§11.1)
        GiftEnvelope::where('status', 'active')->where('reminder_sent', false)->where('expires_at', '<', now()->addHours(2))->each(function (GiftEnvelope $e) {
            foreach ($e->claims()->whereNull('claimed_at')->whereNotNull('recipient_id')->get() as $c) {
                $this->notify->toUser($c->recipient, 'gift_reminder', 'Votre cadeau expire bientôt', 'Récupérez-le avant ' . $e->expires_at->format('H:i') . '.', ['data' => ['gift_code' => $e->code], 'sms' => false]);
            }
            $e->update(['reminder_sent' => true]);
        });

        return $n;
    }

    /** L'expéditeur annule son cadeau : les parts non récupérées lui sont recréditées tout de suite. */
    public function cancel(User $sender, string $code): GiftEnvelope
    {
        $env = GiftEnvelope::where('code', strtoupper($code))->where('sender_id', $sender->id)->first();
        if (! $env || $env->status !== 'active') {
            throw new BusinessException('Ce cadeau n\'est plus actif.', 'gift_closed');
        }
        $this->closeEnvelope($env, 'cancelled');

        return $env->fresh('claims');
    }

    /** Clôture une enveloppe (expirée ou annulée) et recrédite le reste à l'expéditeur. */
    protected function closeEnvelope(GiftEnvelope $env, string $why): bool
    {
        return DB::transaction(function () use ($env, $why) {
                $e = GiftEnvelope::whereKey($env->id)->lockForUpdate()->first();
                if ($e->status !== 'active') {
                    return false;
                }
                if ($e->remaining_amount > 0) {
                    $wallet = Wallet::findOrFail($e->wallet_id);
                    $tx = Transaction::create([
                        'reference' => 'FP-' . Str::upper(Str::random(12)),
                        'type' => 'gift_refund', 'scope' => 'national',
                        'source_rail' => 'wallet', 'destination_rail' => 'wallet',
                        'destination_wallet_id' => $wallet->id,
                        'amount' => $e->remaining_amount, 'currency' => $e->currency,
                        'status' => 'successful', 'completed_at' => now(),
                        'initiated_by' => $e->sender_id,
                        'meta' => ['channel' => 'gift', 'gift_code' => $e->code, 'sender_name' => 'FlashPay', 'note' => $why === 'cancelled' ? 'Cadeau annulé' : 'Cadeau non réclamé'],
                    ]);
                    $this->wallets->credit($wallet, $e->remaining_amount);
                    $this->ledger->recordDoubleEntry($tx, self::ACCOUNT, "wallet:{$wallet->id}", $e->remaining_amount, null, $why === 'cancelled' ? 'Cadeau annulé — recrédit' : 'Cadeau non réclamé — recrédit');
                    $this->notify->toUser($e->sender, 'gift_expired', ($why === 'cancelled' ? 'Cadeau annulé : ' : 'Cadeau expiré : ') . number_format($e->remaining_amount, 0, ',', ' ') . " {$e->currency} recrédités", null, ['sms' => false]);
                }
                $status = $e->remaining_amount > 0 ? ($why === 'cancelled' ? 'cancelled' : 'refunded') : 'completed';
                $e->update(['status' => $status, 'refunded_amount' => $e->remaining_amount, 'remaining_amount' => 0]);
                return true;
        });
    }

    // ------------------------------------------------------------ Présentation

    public const OCCASIONS = ['anniversaire' => '🎂 Anniversaire', 'fete' => '🎉 Fête', 'felicitations' => '👏 Félicitations',
        'mariage' => '💍 Mariage', 'naissance' => '👶 Naissance', 'autre' => '🎁 Autre'];

    public const STATUS_LABELS = ['active' => 'En cours', 'completed' => 'Tout récupéré', 'refunded' => 'Expiré — reste recrédité',
        'cancelled' => 'Annulé — reste recrédité', 'expired' => 'Expiré'];

    /** Enveloppe envoyée : chiffres, destinataires et état de chaque part, lien et message de partage. */
    public function presentSent(GiftEnvelope $e): array
    {
        $e->loadMissing('claims.recipient:id,full_name,phone', 'sender:id,full_name');
        $claimed = $e->claims->whereNotNull('claimed_at');
        $link = url('/g/' . $e->code);

        return [
            'code' => $e->code,
            'mode' => $e->mode,
            'mode_label' => $e->mode === 'random' ? 'Enveloppe surprise' : 'Cadeau à des personnes',
            'occasion' => $e->occasion,
            'occasion_label' => self::OCCASIONS[$e->occasion] ?? '🎁 Cadeau',
            'message' => $e->message,
            'currency' => $e->currency,
            'total_amount' => (int) $e->total_amount,
            'claimed_amount' => (int) $claimed->sum('amount'),
            'remaining_amount' => (int) $e->remaining_amount,
            'refunded_amount' => (int) ($e->refunded_amount ?? 0),
            'shares' => (int) $e->shares,
            'claimed_count' => $claimed->count(),
            'status' => $e->status,
            'status_label' => self::STATUS_LABELS[$e->status] ?? $e->status,
            'created_at' => $e->created_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
            'can_cancel' => $e->status === 'active',
            'share_link' => $link,
            'share_text' => $this->shareText($e, $link),
            'claims' => $e->claims->map(fn ($c) => [
                'name' => $c->recipient?->full_name,
                'phone' => $c->recipient_phone ? '+' . ltrim($c->recipient_phone, '+') : null,
                'amount' => (int) $c->amount,
                'claimed' => (bool) $c->claimed_at,
                'claimed_at' => $c->claimed_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** Cadeau reçu (ou à ouvrir). */
    public function presentReceived(GiftClaim $c): array
    {
        $e = $c->envelope;
        return [
            'code' => $e?->code,
            'sender' => $e?->sender?->full_name,
            'mode' => $e?->mode,
            'occasion_label' => self::OCCASIONS[$e?->occasion] ?? '🎁 Cadeau',
            'message' => $e?->message,
            'currency' => $e?->currency ?? 'XAF',
            'amount' => $c->claimed_at ? (int) $c->amount : null, // montant révélé à l'ouverture
            'claimed' => (bool) $c->claimed_at,
            'claimed_at' => $c->claimed_at?->toIso8601String(),
            'can_open' => ! $c->claimed_at && $e?->status === 'active' && $e->expires_at?->isFuture(),
            'expires_at' => $e?->expires_at?->toIso8601String(),
            'status' => $c->claimed_at ? 'claimed' : ($e?->status === 'active' ? 'to_open' : 'expired'),
        ];
    }

    public function shareText(GiftEnvelope $e, string $link): string
    {
        $occasion = trim(preg_replace('/^\S+\s/u', '', self::OCCASIONS[$e->occasion] ?? '') ?: '');
        $intro = $e->mode === 'random'
            ? "🧧 {$e->sender->full_name} partage une enveloppe surprise FlashPay" . ($occasion ? " ({$occasion})" : '') . ' : ' . $e->shares . ' parts à gagner !'
            : "🧧 {$e->sender->full_name} vous offre un cadeau FlashPay" . ($occasion ? " ({$occasion})" : '') . '.';
        return $intro
            . ($e->message ? "\n« {$e->message} »" : '')
            . "\n\nOuvrez-le ici : {$link}\nCode : {$e->code} — valable jusqu'au " . $e->expires_at->format('d/m/Y à H:i') . '.';
    }
}
