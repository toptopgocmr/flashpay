<?php

namespace App\Services\Merchant;

use App\Exceptions\BusinessException;
use App\Models\Merchant;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Peex\PeexFlowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demandes de paiement marchand (§3.2.2, §4.3, §4.4) :
 *   dynamic_qr   : QR à usage unique, montant figé, expiration courte, signé côté serveur
 *   payment_link : lien de paiement envoyé au client (vente à distance)
 *   nfc          : session NFC (le client confirme par PIN, jamais par simple proximité)
 * Le QR contient un jeton (flashpay://pay?r=…&s=…) résolu par l'API au moment du scan.
 */
class PaymentRequestService
{
    public function __construct(protected PeexFlowService $flows, protected NotificationService $notify)
    {
    }

    public function merchantOf(User $user): array
    {
        if ($user->merchant) {
            return [$user->merchant, null];
        }
        $c = $user->cashier;
        if ($c && $c->status === 'active') {
            return [$c->merchant, $c];
        }
        throw new BusinessException('Compte marchand introuvable.', 'not_merchant', 403);
    }

    public function create(User $creator, string $kind, int $amount, array $opt = []): PaymentRequest
    {
        [$merchant, $cashier] = $this->merchantOf($creator);
        if ($merchant->validation_status !== 'approved') {
            throw new BusinessException('Votre compte marchand doit être validé pour encaisser.', 'merchant_not_approved', 403);
        }
        $ttl = match ($kind) {
            'dynamic_qr' => config('security.dynamic_qr_ttl', 180),
            'nfc' => 120,
            default => 3600 * (int) ($opt['expires_in_hours'] ?? 168),
        };
        $outletId = $cashier?->outlet_id ?? ($opt['outlet_id'] ?? null);
        if ($outletId && ! $merchant->outlets()->whereKey($outletId)->exists()) {
            throw new BusinessException('Point de vente inconnu.', 'outlet_not_found');
        }

        return PaymentRequest::create([
            'token' => Str::lower(Str::random(24)),
            'kind' => $kind,
            'merchant_id' => $merchant->id,
            'outlet_id' => $outletId,
            'created_by' => $creator->id,
            'amount' => $amount,
            'currency' => $merchant->user->wallet?->currency ?? 'XAF',
            'reference' => $opt['reference'] ?? null,
            'description' => $opt['description'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addSeconds($ttl),
        ]);
    }

    public function signature(PaymentRequest $r): string
    {
        return substr(hash_hmac('sha256', $r->token . '|' . $r->amount . '|' . $r->currency, (string) config('app.key', 'flashpay')), 0, 16);
    }

    public function present(PaymentRequest $r, bool $forMerchant = false): array
    {
        $out = [
            'token' => $r->token,
            'kind' => $r->kind,
            'amount' => $r->amount,
            'currency' => $r->currency,
            'description' => $r->description,
            'reference' => $r->reference,
            'status' => $r->status,
            'expires_at' => $r->expires_at,
            'seconds_left' => max(0, now()->diffInSeconds($r->expires_at, false)),
            'merchant' => ['name' => $r->merchant?->business_name, 'code' => $r->merchant?->qr_code_token, 'outlet' => $r->outlet?->name],
            'qr_payload' => "flashpay://pay?r={$r->token}&s=" . $this->signature($r),
            'link' => url("/p/{$r->token}"),
        ];
        if ($forMerchant) {
            $out += ['id' => $r->id, 'transaction_id' => $r->transaction_id, 'paid_at' => $r->paid_at, 'payer' => $r->paid_by ? User::find($r->paid_by)?->full_name : null];
        }
        return $out;
    }

    public function find(string $token, ?string $sig = null): PaymentRequest
    {
        $r = PaymentRequest::with('merchant', 'outlet')->where('token', $token)->first();
        if (! $r || ($sig !== null && ! hash_equals($this->signature($r), $sig))) {
            throw new BusinessException('QR code ou lien de paiement invalide (signature incorrecte).', 'invalid_request', 404);
        }
        if ($r->status === 'pending' && $r->expires_at->isPast()) {
            $this->expire($r);
        }
        return $r->fresh(['merchant', 'outlet']);
    }

    /** Paiement d'une demande (confirmé par PIN via le middleware). */
    public function pay(User $payer, string $token, ?string $sig, string $source = 'wallet', ?string $sourcePhone = null): Transaction
    {
        return DB::transaction(function () use ($payer, $token, $sig, $source, $sourcePhone) {
            $r = PaymentRequest::where('token', $token)->lockForUpdate()->first();
            if (! $r || ($sig !== null && ! hash_equals($this->signature($r), $sig))) {
                throw new BusinessException('QR code invalide.', 'invalid_request', 404);
            }
            if (! $r->isPayable()) {
                throw new BusinessException($r->status === 'paid' ? 'Cette demande a déjà été payée.' : 'Ce QR code a expiré. Demandez au marchand d\'en générer un nouveau.', 'request_not_payable');
            }
            if ($r->transaction_id && Transaction::whereKey($r->transaction_id)->where('status', 'processing')->exists()) {
                throw new BusinessException('Un paiement est déjà en cours pour cette demande.', 'payment_in_progress', 409);
            }

            $cashier = $r->creator?->cashier;
            $tx = $this->flows->payMerchant($payer, $payer, $r->merchant, $source, $sourcePhone, $r->amount, $r->kind === 'nfc' ? 'nfc' : ($r->kind === 'dynamic_qr' ? 'dynamic_qr' : 'payment_link'), [
                'outlet_id' => $r->outlet_id,
                'meta' => array_filter([
                    'payment_request_id' => $r->id,
                    'cashier_user_id' => $cashier ? $r->created_by : null,
                    'cashier_id' => $cashier?->id,
                    'order_reference' => $r->reference,
                    'method' => $r->kind === 'nfc' ? 'nfc' : ($r->kind === 'dynamic_qr' ? 'dynamic_qr' : 'payment_link'),
                ]),
            ]);

            $r->update(['transaction_id' => $tx->id]);
            if ($tx->status === 'successful') {
                $r->update(['status' => 'paid', 'paid_by' => $payer->id, 'paid_at' => now()]);
            }
            return $tx;
        });
    }

    public function cancel(PaymentRequest $r): PaymentRequest
    {
        if ($r->status !== 'pending') {
            throw new BusinessException('Demande non annulable.');
        }
        $r->update(['status' => 'cancelled']);
        return $r;
    }

    public function expire(PaymentRequest $r): void
    {
        if ($r->status !== 'pending') {
            return;
        }
        $r->update(['status' => 'expired']);
        if ($r->kind === 'dynamic_qr' && ($u = $r->creator)) {
            // §11.3 — QR dynamique expiré sans paiement reçu
            $this->notify->toUser($u, 'dynamic_qr_expired', 'QR dynamique expiré', number_format($r->amount, 0, ',', ' ') . " {$r->currency} — aucun paiement reçu.", ['sms' => false, 'data' => ['token' => $r->token]]);
        }
    }

    public function expireDue(): int
    {
        $n = 0;
        PaymentRequest::where('status', 'pending')->where('expires_at', '<', now())->each(function ($r) use (&$n) {
            $this->expire($r);
            $n++;
        });
        return $n;
    }

    /** Suites d'une transaction asynchrone (mobile money) liée à une demande ou un payment intent. */
    public function onTransactionFinal(Transaction $tx): void
    {
        $meta = $tx->meta ?? [];
        if (! empty($meta['payment_request_id']) && $tx->status === 'successful') {
            PaymentRequest::whereKey($meta['payment_request_id'])->where('status', 'pending')
                ->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $tx->source_wallet_id ? \App\Models\Wallet::find($tx->source_wallet_id)?->user_id : null]);
        }
        if (! empty($meta['payment_intent_id'])) {
            app(\App\Services\Ecommerce\PaymentIntentService::class)->onTransactionFinal($tx);
        }
    }
}
