<?php

namespace App\Services\Ecommerce;

use App\Exceptions\BusinessException;
use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Merchant\RefundService;
use App\Services\Notifications\NotificationService;
use App\Services\Peex\PeexFlowService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API de paiement e-commerce (§3.2.5, §4.7) : clés API, intentions de paiement,
 * confirmation client (PIN dans l'app ou page web), webhooks et remboursements.
 * En sandbox, aucun fonds réel n'est déplacé : le paiement est simulé.
 */
class PaymentIntentService
{
    public function __construct(
        protected PeexFlowService $flows,
        protected WebhookService $webhooks,
        protected NotificationService $notify,
    ) {
    }

    // ------------------------------------------------------------ Clés API

    /** @return array{key: MerchantApiKey, secret_key: string, webhook_secret: string} */
    public function issueKeys(Merchant $m, string $env, array $opt = []): array
    {
        if ($env === 'live' && $m->validation_status !== 'approved') {
            throw new BusinessException('Le compte marchand doit être validé pour obtenir des clés de production.', 'merchant_not_approved', 403);
        }
        if ($env === 'live' && config('security.require_sandbox_validation')) {
            $st = app(IntegrationStatusService::class)->status($m->fresh());
            if (! in_array($st['status'], ['sandbox_validated', 'live_pending', 'live'], true)) {
                $missing = collect($st['steps'])->where('required', true)->where('done', false)->pluck('label')->implode(', ');
                throw new BusinessException("Terminez d'abord les tests en sandbox ({$st['progress']['done']}/{$st['progress']['total']}). Étapes restantes : {$missing}.", 'integration_not_validated', 422, ['integration' => $st]);
            }
        }
        MerchantApiKey::where('merchant_id', $m->id)->where('environment', $env)->update(['active' => false]);

        $secret = "sk_{$env}_" . Str::random(40);
        $whsec = 'whsec_' . Str::random(32);
        $key = MerchantApiKey::create([
            'merchant_id' => $m->id,
            'environment' => $env,
            'public_key' => "pk_{$env}_" . Str::random(24),
            'secret_hash' => hash('sha256', $secret),
            'secret_last4' => substr($secret, -4),
            'webhook_url' => $opt['webhook_url'] ?? null,
            'webhook_secret' => $whsec,
            'allowed_ips' => $opt['allowed_ips'] ?? null,
        ]);
        $m->update(['online_payments' => true]);
        Audit::log('api_key.issue', $key, ['environment' => $env]);

        return ['key' => $key, 'secret_key' => $secret, 'webhook_secret' => $whsec];
    }

    // --------------------------------------------------------- Intentions

    public function create(MerchantApiKey $key, array $d, string $channel = 'ecommerce'): PaymentIntent
    {
        $m = $key->merchant;
        if ($key->environment === 'live' && $m->validation_status !== 'approved') {
            throw new BusinessException('Compte marchand non validé.', 'merchant_not_approved', 403);
        }
        $currency = strtoupper($d['currency'] ?? ($m->user->wallet?->currency ?? 'XAF'));
        if ($currency !== ($m->user->wallet?->currency ?? 'XAF')) {
            throw new BusinessException("Devise non prise en charge pour ce marchand ({$currency}).", 'currency_not_supported');
        }

        return PaymentIntent::create([
            'public_id' => 'pi_' . Str::lower(Str::random(24)),
            'merchant_id' => $m->id,
            'api_key_id' => $key->id,
            'environment' => $key->environment,
            'channel' => $channel,
            'amount' => (int) $d['amount'],
            'currency' => $currency,
            'order_reference' => $d['order_reference'] ?? null,
            'description' => $d['description'] ?? null,
            'customer_phone' => $d['customer_phone'] ?? null,
            'return_url' => $d['return_url'] ?? null,
            'cancel_url' => $d['cancel_url'] ?? null,
            'metadata' => $d['metadata'] ?? null,
            'status' => 'requires_confirmation',
            'amount_refunded' => 0,
            'expires_at' => now()->addMinutes((int) ($d['expires_in_minutes'] ?? 30)),
        ]);
    }

    public function present(PaymentIntent $pi): array
    {
        $this->expireIfDue($pi);
        return [
            'id' => $pi->public_id,
            'object' => 'payment_intent',
            'livemode' => $pi->environment === 'live',
            'amount' => $pi->amount,
            'amount_refunded' => $pi->amount_refunded,
            'currency' => $pi->currency,
            'status' => $this->publicStatus($pi->status),
            'order_reference' => $pi->order_reference,
            'description' => $pi->description,
            'merchant' => ['name' => $pi->merchant?->business_name],
            'checkout_url' => url("/checkout/{$pi->public_id}"),
            'qr_payload' => "flashpay://intent?id={$pi->public_id}",
            'return_url' => $pi->return_url,
            'failure_reason' => $pi->failure_reason,
            'transaction_reference' => $pi->transaction?->reference,
            'metadata' => $pi->metadata,
            'expires_at' => $pi->expires_at?->toIso8601String(),
            'created' => $pi->created_at?->timestamp,
        ];
    }

    /** Statuts publics : pending | confirmed | failed | expired | refunded (+ détails). */
    public function publicStatus(string $s): string
    {
        return match ($s) {
            'requires_confirmation', 'processing' => 'pending',
            'succeeded' => 'confirmed',
            'partially_refunded' => 'partially_refunded',
            default => $s,
        };
    }

    /**
     * Déclenche la confirmation côté client : notification dans l'app FlashPay du
     * numéro fourni + lien de paiement web. Sandbox : test_outcome=succeeded|failed.
     */
    public function confirm(PaymentIntent $pi, array $d): array
    {
        $this->assertPayable($pi);
        if (! empty($d['customer_phone'])) {
            $pi->update(['customer_phone' => $d['customer_phone']]);
        }

        if ($pi->environment === 'sandbox' && ! empty($d['test_outcome'])) {
            $d['test_outcome'] === 'succeeded' ? $this->markSucceeded($pi, null) : $this->markFailed($pi, 'Paiement refusé (simulation sandbox).');
            return ['next_action' => null, 'payment_intent' => $this->present($pi->fresh())];
        }

        $notified = false;
        if ($pi->customer_phone && ($user = $this->flows->findUserByPhone($pi->customer_phone))) {
            $this->notify->toUser($user, 'payment_request', 'Paiement à confirmer : ' . number_format($pi->amount, 0, ',', ' ') . " {$pi->currency}", ($pi->merchant->business_name) . ($pi->order_reference ? " — commande {$pi->order_reference}" : ''), ['data' => ['payment_intent' => $pi->public_id]]);
            $notified = true;
        }

        return [
            'next_action' => ['type' => 'redirect_to_url', 'url' => url("/checkout/{$pi->public_id}"), 'app_notified' => $notified],
            'payment_intent' => $this->present($pi->fresh()),
        ];
    }

    /** Paiement par le client connecté (app FlashPay ou page de checkout), PIN déjà vérifié. */
    public function payByUser(User $payer, PaymentIntent $pi): PaymentIntent
    {
        return DB::transaction(function () use ($payer, $pi) {
            $pi = PaymentIntent::whereKey($pi->id)->lockForUpdate()->first();
            $this->assertPayable($pi);

            if ($pi->environment === 'sandbox') {
                $pi->update(['payer_id' => $payer->id]);
                return $this->markSucceeded($pi, null);
            }

            $pi->update(['status' => 'processing', 'payer_id' => $payer->id]);
            $tx = $this->flows->payMerchant($payer, $payer, $pi->merchant, 'wallet', null, $pi->amount, $pi->channel === 'mini_program' ? 'mini_program' : 'ecommerce', [
                'meta' => array_filter(['payment_intent_id' => $pi->id, 'payment_intent' => $pi->public_id, 'order_reference' => $pi->order_reference]),
            ]);
            $pi->update(['transaction_id' => $tx->id]);

            return match ($tx->status) {
                'successful' => $this->markSucceeded($pi, $tx),
                'processing' => $pi->fresh(),
                default => $this->markFailed($pi, $tx->failure_reason ?? 'Paiement refusé.'),
            };
        });
    }

    public function onTransactionFinal(Transaction $tx): void
    {
        $pi = PaymentIntent::find($tx->meta['payment_intent_id'] ?? 0);
        if (! $pi || ! in_array($pi->status, ['processing', 'requires_confirmation'], true)) {
            return;
        }
        $tx->status === 'successful' ? $this->markSucceeded($pi, $tx) : $this->markFailed($pi, $tx->failure_reason ?? 'Paiement refusé.');
    }

    public function refund(PaymentIntent $pi, ?int $amount, ?string $reason, ?User $by = null): array
    {
        if (! in_array($pi->status, ['succeeded', 'partially_refunded'], true)) {
            throw new BusinessException('Seul un paiement confirmé peut être remboursé.', 'not_refundable');
        }
        $amount ??= $pi->amount - $pi->amount_refunded;
        if ($amount <= 0 || $pi->amount_refunded + $amount > $pi->amount) {
            throw new BusinessException('Montant de remboursement invalide.', 'refund_amount');
        }

        if ($pi->environment === 'sandbox') {
            $refund = \App\Models\Refund::make(['public_id' => 're_test_' . Str::lower(Str::random(16)), 'amount' => $amount, 'currency' => $pi->currency, 'reason' => $reason, 'status' => 'completed']);
        } else {
            $refund = app(RefundService::class)->refund($pi->transaction, $amount, 'api', $by, $reason, $pi->id);
        }
        $total = $pi->amount_refunded + $amount;
        $pi->update(['amount_refunded' => $total, 'status' => $total >= $pi->amount ? 'refunded' : 'partially_refunded']);

        $object = ['id' => $refund->public_id, 'object' => 'refund', 'payment_intent' => $pi->public_id, 'amount' => $amount, 'currency' => $pi->currency, 'reason' => $reason, 'status' => 'completed'];
        if ($pi->apiKey) {
            $this->webhooks->dispatch($pi->apiKey, 'refund.completed', $object);
        }
        return $object;
    }

    public function expireDue(): int
    {
        $n = 0;
        PaymentIntent::where('status', 'requires_confirmation')->where('expires_at', '<', now())->each(function ($pi) use (&$n) {
            $this->expireIfDue($pi);
            $n++;
        });
        return $n;
    }

    // ------------------------------------------------------------ Helpers

    protected function assertPayable(PaymentIntent $pi): void
    {
        $this->expireIfDue($pi);
        if ($pi->status !== 'requires_confirmation') {
            throw new BusinessException('Ce paiement n\'est plus en attente (statut : ' . $this->publicStatus($pi->status) . ').', 'intent_not_payable');
        }
    }

    protected function expireIfDue(PaymentIntent $pi): void
    {
        if ($pi->status === 'requires_confirmation' && $pi->expires_at->isPast()) {
            $pi->update(['status' => 'expired']);
            if ($pi->apiKey) {
                $this->webhooks->dispatch($pi->apiKey, 'payment.expired', $this->present($pi->fresh()));
            }
        }
    }

    protected function markSucceeded(PaymentIntent $pi, ?Transaction $tx): PaymentIntent
    {
        $pi->update(['status' => 'succeeded', 'succeeded_at' => now(), 'transaction_id' => $tx?->id ?? $pi->transaction_id, 'failure_reason' => null]);
        $pi = $pi->fresh();
        if ($pi->apiKey) {
            $this->webhooks->dispatch($pi->apiKey, 'payment.succeeded', $this->present($pi));
        }
        app(IntegrationStatusService::class)->refresh($pi->merchant);
        return $pi;
    }

    protected function markFailed(PaymentIntent $pi, string $reason): PaymentIntent
    {
        $pi->update(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 190)]);
        $pi = $pi->fresh();
        if ($pi->apiKey) {
            $this->webhooks->dispatch($pi->apiKey, 'payment.failed', $this->present($pi));
        }
        return $pi;
    }
}
