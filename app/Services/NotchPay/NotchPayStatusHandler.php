<?php

namespace App\Services\NotchPay;

use App\Models\NotchPayRequest;
use App\Services\SwitchService;
use Illuminate\Support\Facades\Log;

/**
 * Applique le statut Notch Pay à la transaction FlashPay.
 * Règle de sécurité : on n'agit QUE sur un statut confirmé par l'API (le
 * webhook sert de déclencheur ; il n'est jamais cru sur parole pour rembourser).
 */
class NotchPayStatusHandler
{
    public function __construct(protected NotchPayConnector $connector, protected SwitchService $switch)
    {
    }

    public function refresh(NotchPayRequest $req): NotchPayRequest
    {
        if ($req->finalized_at) {
            return $req;
        }

        // Refus explicite à l'envoi (rien créé chez Notch Pay) : déjà certain
        if ($req->status === 'failed' && ! $req->notch_ref) {
            return $this->finalize($req, 'failed', $req->message ?: 'Opération refusée par Notch Pay');
        }

        $check = $this->connector->checkStatus($req->notch_ref ?: $req->reference);
        if (($check['checked'] ?? false) !== true) {
            return $req->fresh(); // API injoignable : on réessaiera
        }
        if ($check['status'] === 'pending') {
            $req->update(['status' => 'pending']);
            return $req->fresh();
        }

        $fresh = $req->fresh();
        return $this->finalize($fresh, $check['status'], (string) ($fresh->message ?: $fresh->raw_status ?: 'statut Notch Pay'));
    }

    /** Webhook reçu : { type|event: "payment.complete", data: { reference, trxref, id… } } → vérification API puis application. */
    public function onCallback(array $payload): ?NotchPayRequest
    {
        $ids = array_values(array_unique(array_filter(self::findIds($payload), fn ($v) => is_scalar($v) && (string) $v !== '')));
        $req = $ids ? NotchPayRequest::whereIn('reference', $ids)->orWhereIn('notch_ref', $ids)->first() : null;
        if (! $req) {
            Log::info('Webhook Notch Pay : aucune opération FlashPay correspondante', ['type' => data_get($payload, 'type') ?? data_get($payload, 'event'), 'ids' => $ids]);
            return null;
        }
        $req->update(['last_callback' => $payload]);

        return $this->refresh($req);
    }

    protected static function findIds(array $a, int $depth = 0): array
    {
        $keys = ['reference', 'trxref', 'merchant_reference', 'id', 'transfer', 'transaction'];
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v) && $depth < 4) {
                $out = array_merge($out, self::findIds($v, $depth + 1));
            } elseif (is_string($k) && in_array(strtolower($k), $keys, true) && is_scalar($v)) {
                $out[] = (string) $v;
            }
        }
        return $out;
    }

    protected function finalize(NotchPayRequest $req, string $status, string $reason): NotchPayRequest
    {
        $req->update(['status' => $status, 'finalized_at' => now()]);
        $tx = $req->transaction;
        if (! $tx) {
            return $req->fresh();
        }
        if ($req->operation === 'payin') {
            // Collecte : validation du client sur son téléphone
            if ($status === 'successful') {
                $tx->status === 'failed'
                    ? app(\App\Services\Peex\PendingTimeoutService::class)->reopenAfterLateSuccess($tx)
                    : $this->switch->onSourceConfirmed($tx);
            } else {
                $this->switch->onSourceFailed($tx, 'Notch Pay : ' . $reason);
            }
        } else {
            $status === 'successful'
                ? $this->switch->onDestinationConfirmed($tx)
                : $this->switch->onDestinationFailed($tx, 'Notch Pay : ' . $reason);
        }
        return $req->fresh();
    }
}
