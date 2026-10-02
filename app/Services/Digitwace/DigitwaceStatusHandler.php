<?php

namespace App\Services\Digitwace;

use App\Models\DigitwaceRequest;
use App\Services\SwitchService;
use Illuminate\Support\Facades\Log;

/**
 * Applique le statut WacePay à la transaction FlashPay.
 * Règle de sécurité : on n'agit QUE sur un statut confirmé par l'API (le
 * webhook sert de déclencheur ; il n'est jamais cru sur parole pour rembourser).
 */
class DigitwaceStatusHandler
{
    public function __construct(protected DigitwaceConnector $connector, protected SwitchService $switch)
    {
    }

    public function refresh(DigitwaceRequest $req): DigitwaceRequest
    {
        if ($req->finalized_at) {
            return $req;
        }

        // Refus explicite à l'envoi : déjà certain
        if ($req->status === 'failed' && ! $req->wace_id) {
            return $this->finalize($req, 'failed', $req->message ?: 'Versement refusé par WacePay');
        }

        $check = $this->connector->checkStatus($req->wace_id ?: $req->reference);
        if (($check['checked'] ?? false) !== true) {
            return $req->fresh(); // API injoignable : on réessaiera
        }
        if ($check['status'] === 'pending') {
            $req->update(['status' => 'pending']);
            return $req->fresh();
        }

        return $this->finalize($req->fresh(), $check['status'], (string) ($req->fresh()->message ?: $req->raw_status ?: 'statut WacePay'));
    }

    /** Webhook reçu : on vérifie auprès de l'API puis on applique. */
    public function onCallback(array $payload): ?DigitwaceRequest
    {
        // Format WacePay : { event: "transaction.success", data: { … } } — on cherche la
        // référence à n'importe quel niveau (reference, externalReference, transactionCode, id…).
        $ids = array_values(array_unique(array_filter(self::findIds($payload), fn ($v) => is_scalar($v) && (string) $v !== '')));
        $req = $ids ? DigitwaceRequest::whereIn('reference', $ids)->orWhereIn('wace_id', $ids)->first() : null;
        if (! $req) {
            // Ex. bouton « TEST » du tableau de bord WacePay : journalisé pour connaître le format exact
            Log::info('Webhook WacePay : aucune transaction FlashPay correspondante', ['event' => data_get($payload, 'event') ?? data_get($payload, 'type'), 'ids' => $ids, 'payload' => $payload]);
            return null;
        }
        $req->update(['last_callback' => $payload]);

        return $this->refresh($req);
    }

    /** Valeurs des clés d'identification, à toute profondeur. */
    protected static function findIds(array $a, int $depth = 0): array
    {
        $keys = ['reference', 'externalreference', 'external_reference', 'merchantreference', 'merchant_reference',
            'transactioncode', 'transaction_code', 'transactionid', 'transaction_id', 'code', 'id'];
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

    protected function finalize(DigitwaceRequest $req, string $status, string $reason): DigitwaceRequest
    {
        $req->update(['status' => $status, 'finalized_at' => now()]);
        $tx = $req->transaction;
        if ($tx) {
            $status === 'successful'
                ? $this->switch->onDestinationConfirmed($tx)
                : $this->switch->onDestinationFailed($tx, 'WacePay : ' . $reason);
        }
        return $req->fresh();
    }
}
