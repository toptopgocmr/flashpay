<?php

namespace App\Services\Remit;

use App\Models\RemitRequest;
use App\Services\SwitchService;

/**
 * Applique le statut d'un transfert international (ou d'une collecte / d'un versement
 * pawaPay national) à la transaction FlashPay.
 * On n'agit QUE sur un statut lu auprès de l'API du partenaire : PAID / 3000 → succès,
 * CANCEL / échec → remboursement automatique du wallet (montant + frais).
 */
class RemitStatusHandler
{
    public function __construct(protected SwitchService $switch)
    {
    }

    public function refresh(RemitRequest $req): RemitRequest
    {
        if ($req->finalized_at) {
            return $req;
        }
        // Refus explicite à l'envoi (rien créé chez le partenaire) : échec certain
        if ($req->status === 'failed' && ! $req->partner_ref) {
            return $this->finalize($req, 'failed', $req->message ?: 'Transfert refusé par le partenaire');
        }
        if (! $req->partner_ref) {
            return $req; // envoi en cours / incertain sans référence : vérification manuelle (console)
        }

        $check = $this->switch->connectorFor($req->partner)->checkStatus($req->partner_ref);
        if (($check['checked'] ?? false) !== true) {
            return $req->fresh();
        }
        if ($check['status'] === 'pending') {
            $req->update(['status' => 'pending']);
            return $req->fresh();
        }
        $fresh = $req->fresh();
        return $this->finalize($fresh, $check['status'], (string) ($fresh->raw_status ?: $fresh->message ?: 'statut partenaire'));
    }

    protected function finalize(RemitRequest $req, string $status, string $reason): RemitRequest
    {
        $req->update(['status' => $status, 'finalized_at' => now()]);
        if ($tx = $req->transaction) {
            $name = $req->partner === 'pawapay_momo' ? 'PawaPay' : RemitCountries::partnerName($req->partner);
            if ($req->method === 'refund') {
                // Remboursement du payeur par pawaPay (partenaire qui a collecté)
                return tap($req->fresh(), fn () => $status === 'successful'
                    ? $this->switch->onRefundConfirmed($tx)
                    : $this->switch->onRefundFailed($tx, $name . ' : ' . $reason));
            }
            if ($req->method === 'deposit') {
                // Collecte pawaPay (passerelle nationale) : le paiement du client
                return tap($req->fresh(), fn () => $status === 'successful'
                    ? $this->switch->onSourceConfirmed($tx)
                    : $this->switch->onSourceFailed($tx, $name . ' : ' . $reason));
            }
            $status === 'successful'
                ? $this->switch->onDestinationConfirmed($tx)
                : $this->switch->onDestinationFailed($tx, $name . ' : ' . $reason);
        }
        return $req->fresh();
    }
}
