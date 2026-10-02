<?php

namespace App\Services\Peex;

use App\Models\PeexRequest;
use App\Services\Connectors\PeexConnector;
use App\Services\SwitchService;
use Illuminate\Support\Facades\Log;

/**
 * Applique un statut PEEX (callback ou polling) à la transaction FlashPay liée.
 *
 * Règles :
 *  - une demande déjà finalisée n'est plus modifiée par un callback tardif
 *    (conflit journalisé en CRITICAL pour la réconciliation) ;
 *  - un échec de versement (D) n'est appliqué — et donc le client remboursé —
 *    qu'après CONFIRMATION du statut auprès de PEEX (all_requests) ;
 *  - selon l'étape (track_id …-C / -D / -R) : collecte, versement ou remboursement.
 */
class PeexStatusHandler
{
    public function __construct(
        protected SwitchService $switch,
        protected PeexConnector $connector,
    ) {
    }

    /** Traite un élément de callback PEEX (un objet du tableau reçu). */
    public function applyCallbackItem(array $item, ?string $service = null): ?PeexRequest
    {
        $trackId = $item['track_id'] ?? null;
        $req = $trackId ? PeexRequest::where('track_id', $trackId)->first() : null;

        if (! $req) {
            Log::warning('Callback PEEX : track_id inconnu', $item);
            return null;
        }

        if ($service && $req->service !== $service) {
            Log::warning('Callback PEEX : service incohérent, ignoré', ['track_id' => $trackId, 'attendu' => $req->service, 'recu' => $service]);
            return null;
        }

        $status = strtolower($item['status'] ?? $req->status);

        if ($req->finalized_at) {
            if ($status !== $req->status) {
                Log::critical('Callback PEEX en conflit avec une demande déjà finalisée', [
                    'track_id' => $trackId, 'statut_final' => $req->status, 'statut_callback' => $status,
                ]);
            }
            $req->update(['last_callback' => $item]);
            return $req;
        }

        $req->fill([
            'status' => $status,
            'peex_id' => $item['id'] ?? $req->peex_id,
            'payment_proof' => isset($item['payment_proof']) && $item['payment_proof'] !== '' ? (string) $item['payment_proof'] : $req->payment_proof,
            'message' => isset($item['message']) && $item['message'] !== '' ? (string) $item['message'] : $req->message,
            'last_callback' => $item,
        ])->save();

        return $this->finalize($req);
    }

    /** Interroge PEEX pour une demande non finalisée puis applique le résultat. */
    public function refresh(PeexRequest $req): PeexRequest
    {
        if ($req->finalized_at) {
            return $req;
        }
        $check = $this->connector->checkStatus($req->track_id);
        $req->refresh();

        return $this->finalize($req, confirmed: ($check['checked'] ?? false) === true);
    }

    public function finalize(PeexRequest $req, bool $confirmed = false): PeexRequest
    {
        if ($req->finalized_at || ! $req->isFinal()) {
            return $req;
        }

        $leg = PeexConnector::legOf($req->track_id);
        $ok = PeexRequest::normalize($req->status) === 'successful';

        // Vérifier avant de rembourser : un échec de versement reçu par callback
        // est confirmé auprès de PEEX. « error » = refus explicite ou demande
        // jamais reçue (déjà vérifiée) : pas de nouvel appel.
        if (! $ok && $leg === 'D' && ! $confirmed && $req->status !== 'error') {
            $check = $this->connector->checkStatus($req->track_id);
            $req->refresh();
            if (($check['checked'] ?? false) !== true) {
                Log::warning('Échec PEEX non confirmé (PEEX injoignable) : remboursement différé', ['track_id' => $req->track_id]);
                return $req; // reste non finalisée : peex:sync réessaiera
            }
            if (! $req->isFinal()) {
                return $req;
            }
            $ok = PeexRequest::normalize($req->status) === 'successful';
        }

        $req->update(['finalized_at' => now()]);
        if ($leg === 'M') {
            // Remboursement manuel (console) : n'altère pas la transaction d'origine
            app(\App\Services\Peex\ManualRefundService::class)->finalized($req, $ok);
            return $req->fresh();
        }
        $tx = $req->transaction;
        if (! $tx) {
            return $req;
        }

        $reason = trim("{$req->status} " . ($req->payment_proof ?? $req->message ?? ''));

        match ($leg) {
            // Collecte validée après expiration du délai : la transaction est rouverte et menée à terme
            'C' => $ok
                ? ($tx->status === 'failed' ? app(PendingTimeoutService::class)->reopenAfterLateSuccess($tx) : $this->switch->onSourceConfirmed($tx))
                : $this->switch->onSourceFailed($tx, $reason),
            'R' => $ok ? $this->switch->onRefundConfirmed($tx) : $this->switch->onRefundFailed($tx, $reason),
            default => $ok ? $this->switch->onDestinationConfirmed($tx) : $this->switch->onDestinationFailed($tx, $reason),
        };

        return $req->fresh();
    }
}
