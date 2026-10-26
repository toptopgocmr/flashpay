<?php

namespace App\Services\Remit;

use App\Models\RemitRequest;
use App\Services\SwitchService;

/**
 * Applique le statut d'un transfert international (ou d'une collecte / d'un versement
 * pawaPay national) à la transaction OfficePay.
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
            $name = match ($req->partner) { 'pawapay_momo' => 'PawaPay', 'maishapay' => 'MaishaPay', 'fincra', 'fincra_remit' => 'Fincra', default => RemitCountries::partnerName($req->partner) };
            if ($req->method === 'checkout') {
                // Page de paiement carte (MaishaPay) : résultat relu auprès de l'API
                $crypto = $tx->source_rail === 'crypto';
                $masked = $crypto ? trim('Crypto ' . ($tx->meta['crypto_asset'] ?? '')) : \App\Support\TransactionPresenter::cardLabel($tx->meta ?? []);
                app(\App\Services\Payments\CardPaymentService::class)->complete((string) $tx->source_external_ref, $status === 'successful',
                    $status === 'successful' ? $masked . ' (' . $name . ')' : null, $status === 'successful' ? null : $name . ' : paiement ' . ($crypto ? 'crypto' : 'par carte') . ' refusé (' . $reason . ')');
                return $req->fresh();
            }
            if (in_array($req->method, ['airtime', 'paytv', 'utility'], true)) {
                app(\App\Services\Bills\BillPaymentService::class)->finalize($tx, $status === 'successful', $name . ' : ' . $reason);
                return $req->fresh();
            }
            if ($req->method === 'refund') {
                // Remboursement du payeur par pawaPay (partenaire qui a collecté)
                return tap($req->fresh(), fn () => $status === 'successful'
                    ? $this->switch->onRefundConfirmed($tx)
                    : $this->switch->onRefundFailed($tx, $name . ' : ' . $reason));
            }
            if ($req->method === 'deposit') {
                // Collecte pawaPay (passerelle nationale) : le paiement du client
                // Validée APRÈS l'expiration du délai : l'argent est pris, on rouvre et on mène l'opération à terme
                return tap($req->fresh(), fn () => $status === 'successful'
                    ? ($tx->fresh()->status === 'failed'
                        ? app(\App\Services\Peex\PendingTimeoutService::class)->reopenAfterLateSuccess($tx)
                        : $this->switch->onSourceConfirmed($tx))
                    : $this->switch->onSourceFailed($tx, $name . ' : ' . $reason));
            }
            $status === 'successful'
                ? $this->switch->onDestinationConfirmed($tx)
                : $this->switch->onDestinationFailed($tx, $name . ' : ' . $reason);
        }
        return $req->fresh();
    }
}
