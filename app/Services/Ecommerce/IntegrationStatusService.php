<?php

namespace App\Services\Ecommerce;

use App\Models\Merchant;
use App\Models\MerchantApiKey;
use App\Models\PaymentIntent;
use App\Models\WebhookDelivery;
use App\Services\Notifications\NotificationService;
use App\Support\Audit;

/**
 * Suivi de l'intégration e-commerce d'un marchand : chaque étape de la recette
 * sandbox est constatée automatiquement à partir des appels réels du site.
 * Quand toutes les étapes obligatoires sont faites, l'intégration est
 * « validée » et les clés de production peuvent être générées.
 */
class IntegrationStatusService
{
    /** Étapes : clé => [libellé, obligatoire, aide]. */
    public const STEPS = [
        'keys' => ['Clés sandbox générées', true, 'Le marchand génère ses clés de test (app › Paiement en ligne).'],
        'api_call' => ['Premier appel API reçu', true, 'Le site a appelé FlashPay avec la clé secrète sandbox.'],
        'intent_created' => ['Paiement créé par le site', true, 'Une commande test a créé un payment intent.'],
        'payment_succeeded' => ['Paiement confirmé', true, 'Un paiement test est allé jusqu\'au statut « confirmé ».'],
        'webhook_delivered' => ['Webhook reçu par le site', true, 'Le serveur du marchand a répondu 2xx à une notification.'],
        'webhook_url' => ['URL de webhook déclarée', true, 'Adresse https du serveur du marchand pour recevoir les notifications.'],
        'payment_failed' => ['Paiement refusé testé', false, 'Simulation d\'un refus (test_outcome=failed).'],
        'refund' => ['Remboursement testé', false, 'Remboursement total ou partiel via POST /v1/refunds.'],
        'idempotency' => ['Clé d\'idempotence utilisée', false, 'Le site envoie un Idempotency-Key unique par commande.'],
    ];

    public function __construct(protected NotificationService $notify)
    {
    }

    public function status(Merchant $m): array
    {
        $sandboxKeys = MerchantApiKey::where('merchant_id', $m->id)->where('environment', 'sandbox')->get();
        $liveKey = MerchantApiKey::where('merchant_id', $m->id)->where('environment', 'live')->where('active', true)->first();
        $intents = PaymentIntent::where('merchant_id', $m->id)->where('environment', 'sandbox');
        $keyIds = $sandboxKeys->pluck('id');
        $activeSandbox = $sandboxKeys->firstWhere('active', true);

        $done = [
            'keys' => $sandboxKeys->isNotEmpty(),
            'api_call' => $sandboxKeys->whereNotNull('last_used_at')->isNotEmpty(),
            'intent_created' => (clone $intents)->exists(),
            'payment_succeeded' => (clone $intents)->whereIn('status', ['succeeded', 'refunded', 'partially_refunded'])->exists(),
            'webhook_delivered' => WebhookDelivery::whereIn('api_key_id', $keyIds)->where('status', 'delivered')->exists(),
            'webhook_url' => (bool) $activeSandbox?->webhook_url,
            'payment_failed' => (clone $intents)->where('status', 'failed')->exists(),
            'refund' => (clone $intents)->whereIn('status', ['refunded', 'partially_refunded'])->exists(),
            'idempotency' => \App\Models\IdempotencyKey::whereIn('scope', $keyIds->map(fn ($id) => "key:{$id}"))->exists(),
        ];

        $steps = [];
        foreach (self::STEPS as $k => [$label, $required, $help]) {
            $steps[] = ['key' => $k, 'label' => $label, 'required' => $required, 'help' => $help, 'done' => $done[$k]];
        }
        $requiredOk = collect($steps)->where('required', true)->every(fn ($s) => $s['done']);
        $validated = $m->integration_validated_at !== null || $requiredOk;

        $liveOk = $liveKey && PaymentIntent::where('merchant_id', $m->id)->where('environment', 'live')->whereIn('status', ['succeeded', 'refunded', 'partially_refunded'])->exists();

        $status = match (true) {
            $liveOk => 'live',
            $liveKey !== null => 'live_pending',
            $validated => 'sandbox_validated',
            $done['keys'] => 'in_progress',
            default => 'not_started',
        };

        $lastDelivery = WebhookDelivery::where('merchant_id', $m->id)->latest('id')->first();

        return [
            'merchant_id' => $m->id,
            'merchant' => $m->business_name,
            'status' => $status,
            'status_label' => self::label($status),
            'progress' => ['done' => collect($steps)->where('required', true)->where('done', true)->count(), 'total' => collect($steps)->where('required', true)->count()],
            'steps' => $steps,
            'validated_at' => $m->integration_validated_at,
            'validated_by' => $m->integration_validated_by,
            'live_at' => $m->integration_live_at,
            'sandbox_last_call' => $sandboxKeys->max('last_used_at'),
            'live_last_call' => $liveKey?->last_used_at,
            'webhook_url' => $activeSandbox?->webhook_url ?? $liveKey?->webhook_url,
            'last_webhook' => $lastDelivery ? ['event' => $lastDelivery->event, 'status' => $lastDelivery->status, 'code' => $lastDelivery->last_response_code, 'error' => $lastDelivery->last_error, 'at' => $lastDelivery->updated_at] : null,
            'intents' => [
                'sandbox' => (clone $intents)->count(),
                'live' => PaymentIntent::where('merchant_id', $m->id)->where('environment', 'live')->count(),
                'live_succeeded' => PaymentIntent::where('merchant_id', $m->id)->where('environment', 'live')->where('status', 'succeeded')->count(),
            ],
        ];
    }

    public static function label(string $s): string
    {
        return [
            'not_started' => 'Non démarrée',
            'in_progress' => 'Recette sandbox en cours',
            'sandbox_validated' => 'Intégration validée',
            'live_pending' => 'Production : en attente du 1er paiement',
            'live' => 'En production',
        ][$s] ?? $s;
    }

    /**
     * Appelé après chaque événement e-commerce (paiement, webhook) : enregistre
     * la validation automatique et le passage effectif en production, et notifie.
     */
    public function refresh(Merchant $m): void
    {
        $st = $this->status($m);
        if (! $m->integration_validated_at && collect($st['steps'])->where('required', true)->every(fn ($s) => $s['done'])) {
            $m->forceFill(['integration_validated_at' => now(), 'integration_validated_by' => 'auto'])->save();
            $this->notify->toUser($m->user, 'integration_validated', 'Intégration e-commerce validée', 'Toutes les étapes de test sont réussies : vous pouvez générer vos clés de production.', ['severity' => 'success']);
            $this->notify->toAdmins('integration_validated', "Intégration validée : {$m->business_name}", 'Recette sandbox réussie, le marchand peut passer en production.', ['severity' => 'success', 'data' => ['merchant_id' => $m->id]]);
        }
        if (! $m->integration_live_at && $st['status'] === 'live') {
            $m->forceFill(['integration_live_at' => now()])->save();
            $this->notify->toAdmins('integration_live', "En production : {$m->business_name}", 'Premier paiement e-commerce réel confirmé.', ['severity' => 'success', 'data' => ['merchant_id' => $m->id]]);
        }
    }

    public function validateManually(Merchant $m, ?string $note = null): void
    {
        $m->forceFill(['integration_validated_at' => now(), 'integration_validated_by' => 'admin'])->save();
        Audit::log('integration.validate', $m, ['note' => $note]);
        $this->notify->toUser($m->user, 'integration_validated', 'Intégration e-commerce validée par FlashPay', 'Vous pouvez générer vos clés de production.', ['severity' => 'success']);
    }

    public function revokeValidation(Merchant $m, ?string $note = null): void
    {
        $m->forceFill(['integration_validated_at' => null, 'integration_validated_by' => null])->save();
        Audit::log('integration.revoke', $m, ['note' => $note]);
    }
}
