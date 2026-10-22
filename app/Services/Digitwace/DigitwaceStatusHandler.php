<?php

namespace App\Services\Digitwace;

use App\Models\DigitwaceRequest;
use App\Services\SwitchService;
use Illuminate\Support\Facades\Log;

/**
 * Applique le statut WacePay à la transaction OfficePay.
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

        // Versement envoyé avant le correctif « status -1 » : payout/execute avait répondu par un
        // refus (HTTP 201 + status -1) mais la demande était restée « en attente » → échec certain.
        $sent = data_get($req->transaction?->meta, 'wacepay_payout.response');
        if ($req->operation === 'refund') {
            $sent = data_get($req->transaction?->meta, 'wacepay_refund.response');
        }
        if (! $req->wace_id && in_array($req->operation, ['payout', 'refund'], true) && is_array($sent) && DigitwaceClient::refused($sent)) {
            $msg = 'Versement refusé par WacePay : ' . mb_substr((string) data_get($sent, 'message', 'refus'), 0, 200);
            $req->update(['status' => 'failed', 'message' => $msg]);
            return $this->finalize($req->fresh(), 'failed', $msg);
        }

        $check = $this->connector->checkStatus($req->wace_id ?: $req->reference);
        if (($check['checked'] ?? false) !== true) {
            return $req->fresh(); // API injoignable : on réessaiera
        }
        // Versement que WacePay déclare introuvable (refresh-status, transactions ET liste) alors qu'il
        // n'a jamais renvoyé d'identifiant : il n'a pas été créé chez eux. Passé le délai de grâce
        // (DIGITWACE_PAYOUT_NOT_FOUND_SECONDS, 120 s), échec certain → remboursement, sans faire
        // attendre le client des dizaines de minutes.
        if ($check['status'] === 'pending' && in_array($req->operation, ['payout', 'refund'], true) && ! $req->wace_id
            && self::notFound($check['raw'] ?? [])
            && $req->created_at && $req->created_at->lt(now()->subSeconds(max(60, (int) config('flashpay.digitwace.payout_not_found_seconds', 120))))) {
            $msg = 'Versement introuvable chez WacePay (jamais exécuté)';
            $req->update(['status' => 'failed', 'message' => $msg]);
            return $this->finalize($req->fresh(), 'failed', $msg);
        }
        if ($check['status'] === 'pending') {
            $req->update(['status' => 'pending']);
            return $req->fresh();
        }

        return $this->finalize($req->fresh(), $check['status'], (string) ($req->fresh()->message ?: $req->raw_status ?: 'statut WacePay'));
    }

    /** Réponse WacePay « transaction introuvable » (status -1 + not found). */
    public static function notFound(mixed $json): bool
    {
        if (! is_array($json) || ! DigitwaceClient::refused($json)) {
            return false;
        }
        return (bool) preg_match('/not\s*found|introuvable/i', (string) ($json['message'] ?? ''));
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
            Log::info('Webhook WacePay : aucune transaction OfficePay correspondante', ['event' => data_get($payload, 'event') ?? data_get($payload, 'type'), 'ids' => $ids, 'payload' => $payload]);
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
        if ($tx && $req->operation === 'checkout') {
            // Carte / compte bancaire payé sur la page WacePay
            $pan = collect([(array) ($req->last_response ?? []), (array) ($req->last_callback ?? [])])
                ->crossJoin(['data.maskedPan', 'data.cardNumber', 'data.card.last4', 'data.last4', 'data.accountNumber', 'data.iban', 'maskedPan', 'cardNumber', 'last4'])
                ->map(fn ($p) => data_get($p[0], $p[1]))->first(fn ($v) => is_scalar($v) && $v !== '');
            $bank = $tx->source_rail === 'bank';
            $masked = ($bank ? 'Compte bancaire' : 'Carte') . ($pan ? ' •••• ' . substr(preg_replace('/\D/', '', (string) $pan) ?: (string) $pan, -4) : '') . ' (WacePay)';
            app(\App\Services\Payments\CardPaymentService::class)->complete(
                (string) $tx->source_external_ref, $status === 'successful', $masked,
                $status === 'successful' ? null : 'WacePay : ' . ($bank ? 'prélèvement bancaire' : 'paiement par carte') . ' refusé (' . $reason . ')'
            );
            return $req->fresh();
        }
        if ($tx && $req->operation === 'payin') {
            // Collecte : validation du client sur son téléphone
            if ($status === 'successful') {
                $tx->status === 'failed'
                    ? app(\App\Services\Peex\PendingTimeoutService::class)->reopenAfterLateSuccess($tx)
                    : $this->switch->onSourceConfirmed($tx);
            } else {
                $this->switch->onSourceFailed($tx, 'WacePay : ' . $reason);
            }
        } elseif ($tx && $req->operation === 'refund') {
            // Remboursement du payeur par WacePay (partenaire qui a collecté)
            $status === 'successful'
                ? $this->switch->onRefundConfirmed($tx)
                : $this->switch->onRefundFailed($tx, 'WacePay : ' . $reason);
        } elseif ($tx) {
            $status === 'successful'
                ? $this->switch->onDestinationConfirmed($tx)
                : $this->switch->onDestinationFailed($tx, 'WacePay : ' . $reason);
        }
        return $req->fresh();
    }
}
