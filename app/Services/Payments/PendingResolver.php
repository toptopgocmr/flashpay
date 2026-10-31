<?php

namespace App\Services\Payments;

use App\Models\DigitwaceRequest;
use App\Models\PeexRequest;
use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Services\SwitchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Opérations restées « En attente » (status processing) : décision SYSTÉMATIQUE selon les
 * réponses des partenaires, pour qu'aucune opération ne reste figée.
 *
 *  1. Les demandes encore ouvertes chez les partenaires sont relues (PEEX, WacePay, MaishaPay,
 *     PawaPay, Fincra, TerraPay…) : leurs réponses sont appliquées comme d'habitude.
 *  2. Ensuite, pour ce qui reste en attente :
 *     - versement confirmé par le partenaire actuel → opération réussie ;
 *     - versement refusé / aucune demande ouverte → opération rejetée, expéditeur remboursé
 *       (montant + frais) ;
 *     - remboursement sans demande ouverte → wallet OfficePay recrédité ;
 *     - étape « …:handling » figée (traitement interrompu) → étape rétablie puis décision ;
 *     - versement encore OUVERT chez un partenaire → on attend sa réponse (jamais de double paiement),
 *       sauf annulation forcée explicite (--include-open, opérations de plus de 24 h).
 *  3. Mode « tout annuler » (console / commande avec --all) : les collectes et paiements carte
 *     en attente (rien n'a été débité) sont aussi annulés.
 *
 * Jamais touchés : bons de retrait en attente de présentation (awaiting_pickup) et virements
 * de règlement marchand (awaiting_bank), qui ont leur propre échéance.
 */
class PendingResolver
{
    public const ADMIN_REASON = 'Opération annulée par l\'administration (restée en attente)';
    public const WAITING_STAGES = ['awaiting_pickup', 'awaiting_bank'];

    public function __construct(protected SwitchService $switch)
    {
    }

    /** Opérations en attente à traiter (les plus anciennes d'abord). */
    public function candidates(int $minutes = 10, array $references = [], int $limit = 500): Collection
    {
        return Transaction::where('status', 'processing')
            ->when($references, fn ($q) => $q->whereIn('reference', $references))
            ->when(! $references, fn ($q) => $q->where('updated_at', '<', now()->subMinutes(max(0, $minutes))))
            ->where(fn ($q) => $q->whereNull('stage')->orWhereNotIn('stage', self::WAITING_STAGES))
            ->orderBy('id')->limit($limit)->get();
    }

    /**
     * @param  bool  $cancelAll   annuler aussi les collectes / paiements carte en attente
     * @param  bool  $dryRun      simulation : aucune écriture, aucun appel partenaire
     * @param  bool  $includeOpen annuler même si un versement est encore ouvert chez un partenaire (> 24 h)
     * @return array{reference:string, stage:?string, action:string, status:string, detail:string}
     */
    public function resolve(Transaction $tx, bool $cancelAll = false, bool $dryRun = false, bool $includeOpen = false): array
    {
        $row = fn (string $action, string $detail, ?Transaction $t = null) => [
            'reference' => $tx->reference, 'stage' => $tx->stage, 'action' => $action,
            'status' => ($t ?? $tx)->status, 'detail' => mb_substr($detail, 0, 200)];

        $stage = (string) $tx->stage;
        $base = str_ends_with($stage, ':handling') ? substr($stage, 0, -9) : $stage;

        if (in_array($base, self::WAITING_STAGES, true)) {
            return $row('ignorée', 'Attente légitime (' . $base . ') : échéance propre');
        }

        try {
            // Traitement interrompu (avant le correctif du 27/10) : l'étape « :handling » restait figée
            if ($base !== $stage) {
                if ($tx->updated_at?->gt(now()->subMinutes(5))) {
                    return $row('ignorée', 'Traitement en cours à l\'instant');
                }
                if (! $dryRun) {
                    Transaction::whereKey($tx->id)->where('status', 'processing')->where('stage', $stage)->update(['stage' => $base]);
                    $tx->refresh();
                }
            }

            // 1. Réponses des partenaires (demandes ouvertes)
            if (! $dryRun) {
                $this->refreshPartners($tx);
                $tx->refresh();
                if ($tx->status !== 'processing') {
                    return $row('réglée', 'Réponse du partenaire appliquée : ' . ($tx->failure_reason ?: $tx->status), $tx);
                }
                $base = (string) $tx->stage;
            }

            // 2. Décision
            return match (true) {
                $base === 'awaiting_source' => $this->cancelSource($tx, $cancelAll, $dryRun, $row),
                $base === 'awaiting_card' => $this->cancelCard($tx, $cancelAll, $dryRun, $row),
                $base === 'awaiting_refund' => $this->finishRefund($tx, $dryRun, $row),
                default => $this->settlePayout($tx, $base, $cancelAll, $dryRun, $includeOpen, $row),
            };
        } catch (\Throwable $e) {
            Log::error('Résolution d\'une opération en attente impossible', ['reference' => $tx->reference, 'error' => $e->getMessage()]);
            return $row('erreur', $e->getMessage(), $tx->fresh() ?? $tx);
        }
    }

    protected function cancelSource(Transaction $tx, bool $cancelAll, bool $dryRun, callable $row): array
    {
        if (! $cancelAll) {
            return $row('ignorée', 'Collecte en attente : délai de validation automatique');
        }
        if ($this->switch->debitConfirmed($tx)) {
            return $row('ignorée', 'Collecte confirmée par le partenaire : traitement en cours');
        }
        if ($dryRun) {
            return $row('à annuler', 'Collecte jamais confirmée : aucun débit');
        }
        $tx->forceFill(['meta' => ($tx->meta ?? []) + ['validation_timeout' => now()->toIso8601String(), 'cancelled_by_admin' => now()->toIso8601String()]])->saveQuietly();
        $t = $this->switch->onSourceFailed($tx->fresh(), self::ADMIN_REASON . ' : collecte non confirmée, aucun montant débité');
        return $row('annulée', 'Collecte non confirmée : aucun montant débité', $t);
    }

    protected function cancelCard(Transaction $tx, bool $cancelAll, bool $dryRun, callable $row): array
    {
        if (! $cancelAll) {
            return $row('ignorée', 'Paiement carte en attente : délai automatique');
        }
        if ($dryRun) {
            return $row('à annuler', 'Paiement carte / crypto non finalisé');
        }
        app(CardPaymentService::class)->expireAbandoned(0, $tx->id);
        $t = $tx->fresh();
        return $row($t->status === 'processing' ? 'erreur' : 'annulée', 'Paiement carte / crypto non finalisé : aucun débit', $t);
    }

    protected function finishRefund(Transaction $tx, bool $dryRun, callable $row): array
    {
        if ($p = $this->switch->openRefundPartner($tx)) {
            return $row('en attente', 'Remboursement encore en cours chez ' . $p);
        }
        if ($dryRun) {
            return $row('à rembourser', 'Remboursement sans réponse : wallet OfficePay à recréditer');
        }
        $t = $this->switch->onRefundFailed($tx, 'Remboursement sans réponse du partenaire');
        return $row('remboursée', (string) $t->failure_reason, $t);
    }

    protected function settlePayout(Transaction $tx, string $base, bool $cancelAll, bool $dryRun, bool $includeOpen, callable $row): array
    {
        // Étape inconnue sans débit confirmé : rien n'a quitté l'expéditeur
        if ($base !== 'awaiting_destination' && ! $this->switch->debitConfirmed($tx)) {
            if ($dryRun) {
                return $row('à annuler', 'Aucun débit confirmé');
            }
            Transaction::whereKey($tx->id)->update(['stage' => 'awaiting_source']);
            $t = $this->switch->onSourceFailed($tx->fresh(), self::ADMIN_REASON . ' : aucun débit confirmé');
            return $row('annulée', 'Aucun débit confirmé', $t);
        }

        if ($open = $this->switch->openPayoutPartner($tx)) {
            $old = $tx->updated_at?->lt(now()->subHours(24));
            if (! ($cancelAll && $includeOpen && $old)) {
                return $row('en attente', 'Versement encore ouvert chez ' . $open . ' : réponse du partenaire attendue');
            }
            Log::critical('Annulation forcée d\'un versement encore ouvert chez un partenaire (double versement possible)', ['reference' => $tx->reference, 'partner' => $open]);
        }

        if ($this->switch->payoutPaidByCurrentPartner($tx)) {
            if ($dryRun) {
                return $row('à valider', 'Versement confirmé par le partenaire');
            }
            Transaction::whereKey($tx->id)->update(['stage' => 'awaiting_destination']);
            $t = $this->switch->onDestinationConfirmed($tx->fresh());
            return $row('réussie', 'Versement confirmé par le partenaire', $t);
        }
        if ($this->switch->beneficiaryCredited($tx)) {
            // Payé par un ANCIEN partenaire (après bascule) : jamais de remboursement automatique
            Log::critical('Opération en attente déjà payée par un ancien partenaire : vérification manuelle', ['reference' => $tx->reference]);
            return $row('à vérifier', 'Bénéficiaire déjà crédité par un ancien partenaire : vérification manuelle');
        }

        $reason = $this->lastRefusal($tx);
        if ($dryRun) {
            return $row('à rejeter', $reason . ' → expéditeur remboursé');
        }
        Transaction::whereKey($tx->id)->update(['stage' => 'awaiting_destination']);
        $t = $this->switch->cancelPayout($tx->fresh(), $reason);
        return $row($t->status === 'processing' ? 'erreur' : 'rejetée', (string) $t->failure_reason, $t);
    }

    /** Relit les demandes encore ouvertes chez les partenaires (les réponses sont appliquées). */
    protected function refreshPartners(Transaction $tx): void
    {
        $try = function (callable $fn, string $who) use ($tx) {
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::info("{$who} : vérification impossible", ['reference' => $tx->reference, 'error' => $e->getMessage()]);
            }
        };
        foreach (PeexRequest::where('transaction_id', $tx->id)->whereNull('finalized_at')->get() as $r) {
            $try(fn () => app(\App\Services\Peex\PeexStatusHandler::class)->refresh($r), 'PEEX');
        }
        foreach (DigitwaceRequest::where('transaction_id', $tx->id)->whereNull('finalized_at')->get() as $r) {
            $try(fn () => app(\App\Services\Digitwace\DigitwaceStatusHandler::class)->refresh($r), 'WacePay');
        }
        foreach (RemitRequest::where('transaction_id', $tx->id)->whereNull('finalized_at')->get() as $r) {
            $try(fn () => app(\App\Services\Remit\RemitStatusHandler::class)->refresh($r), (string) $r->partner);
        }
    }

    /** Dernier refus connu d'un partenaire (message affiché au client et dans la console). */
    protected function lastRefusal(Transaction $tx): string
    {
        $msgs = collect()
            ->merge(PeexRequest::where('transaction_id', $tx->id)->whereNotNull('message')->get()->map(fn ($r) => ['at' => $r->updated_at, 'msg' => 'PEEX : ' . $r->message]))
            ->merge(DigitwaceRequest::where('transaction_id', $tx->id)->whereNotNull('message')->get()->map(fn ($r) => ['at' => $r->updated_at, 'msg' => 'WacePay : ' . $r->message]))
            ->merge(RemitRequest::where('transaction_id', $tx->id)->whereNotNull('message')->get()->map(fn ($r) => ['at' => $r->updated_at, 'msg' => (string) $r->message]))
            ->sortByDesc('at');
        $last = $msgs->first()['msg'] ?? null;
        if (! $last) {
            $attempts = (array) (($tx->meta ?? [])['payout_attempts'] ?? []);
            $a = end($attempts) ?: null;
            $last = $a ? (($a['partner'] ?? '') . ' : ' . ($a['error'] ?? '')) : 'Versement non effectué, aucune réponse exploitable du partenaire';
        }
        return mb_substr('Versement rejeté — ' . $last, 0, 200);
    }
}
