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
            DB::transaction(function () use ($env, &$n) {
                $e = GiftEnvelope::whereKey($env->id)->lockForUpdate()->first();
                if ($e->status !== 'active') {
                    return;
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
                        'meta' => ['channel' => 'gift', 'gift_code' => $e->code],
                    ]);
                    $this->wallets->credit($wallet, $e->remaining_amount);
                    $this->ledger->recordDoubleEntry($tx, self::ACCOUNT, "wallet:{$wallet->id}", $e->remaining_amount, null, 'Cadeau non réclamé — recrédit');
                    $this->notify->toUser($e->sender, 'gift_expired', 'Cadeau expiré : ' . number_format($e->remaining_amount, 0, ',', ' ') . " {$e->currency} recrédités", null, ['sms' => false]);
                }
                $e->update(['status' => $e->remaining_amount > 0 ? 'refunded' : 'completed', 'remaining_amount' => 0]);
                $n++;
            });
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
}
