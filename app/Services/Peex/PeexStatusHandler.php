<?php

namespace App\Services\Peex;

use App\Models\PeexRequest;
use App\Services\Connectors\PeexConnector;
use App\Services\SwitchService;
use Illuminate\Support\Facades\Log;

/**
 * Applique un statut PEEX (callback ou polling) à la transaction FlashPay liée.
 */
class PeexStatusHandler
{
    public function __construct(
        protected SwitchService $switch,
        protected PeexConnector $connector,
    ) {
    }

    /** Traite un élément de callback PEEX (un objet du tableau reçu). */
    public function applyCallbackItem(array $item): ?PeexRequest
    {
        $trackId = $item['track_id'] ?? null;
        $req = $trackId ? PeexRequest::where('track_id', $trackId)->first() : null;

        if (! $req) {
            Log::warning('Callback PEEX : track_id inconnu', $item);
            return null;
        }

        $req->fill([
            'status' => strtolower($item['status'] ?? $req->status),
            'peex_id' => $item['id'] ?? $req->peex_id,
            'payment_proof' => isset($item['payment_proof']) && $item['payment_proof'] !== '' ? (string) $item['payment_proof'] : $req->payment_proof,
            'message' => isset($item['message']) && $item['message'] !== '' ? (string) $item['message'] : $req->message,
            'last_callback' => $item,
        ])->save();

        return $this->finalize($req);
    }

    /** Interroge PEEX pour une demande en attente puis applique le résultat. */
    public function refresh(PeexRequest $req): PeexRequest
    {
        if (! $req->finalized_at) {
            $this->connector->checkStatus($req->track_id);
            $req->refresh();
        }
        return $this->finalize($req);
    }

    public function finalize(PeexRequest $req): PeexRequest
    {
        if ($req->finalized_at || ! $req->isFinal()) {
            return $req;
        }

        $req->update(['finalized_at' => now()]);
        $tx = $req->transaction;
        if (! $tx) {
            return $req;
        }

        $ok = PeexRequest::normalize($req->status) === 'successful';
        $reason = trim("{$req->status} " . ($req->payment_proof ?? $req->message ?? ''));

        if ($req->service === 'collect') {
            $ok ? $this->switch->onSourceConfirmed($tx) : $this->switch->onSourceFailed($tx, $reason);
        } else {
            $ok ? $this->switch->onDestinationConfirmed($tx) : $this->switch->onDestinationFailed($tx, $reason);
        }

        return $req->fresh();
    }
}
