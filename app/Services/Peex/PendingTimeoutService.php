<?php

namespace App\Services\Peex;

use App\Models\Transaction;
use App\Services\SwitchService;
use Illuminate\Support\Facades\Log;

/**
 * Délai de validation des opérations « Validez sur le téléphone ».
 *
 * Avant : une collecte mobile money jamais validée par le client restait
 * « En cours » indéfiniment (écran d'attente sans fin, historique pollué).
 *
 * Maintenant : passé PEEX_VALIDATION_TIMEOUT_SECONDS (180 s par défaut) sans
 * confirmation, on interroge PEEX une dernière fois ; si la collecte n'est
 * toujours pas validée, la transaction passe en « Échouée — délai dépassé ».
 * Aucun argent n'a été débité à ce stade (étape awaiting_source).
 *
 * Validation tardive : la demande PEEX reste suivie (peex:sync). Si PEEX
 * confirme finalement le débit, la transaction est rouverte et menée à son
 * terme (wallet crédité / versement) — voir reopenAfterLateSuccess().
 */
class PendingTimeoutService
{
    public const REASON = 'Délai de validation dépassé : paiement non confirmé sur le téléphone';

    public function __construct(protected SwitchService $switch, protected PeexStatusHandler $handler)
    {
    }

    public static function timeoutSeconds(): int
    {
        return max(60, (int) config('flashpay.peex.validation_timeout_seconds', 180));
    }

    /** Échéance de validation (affichée en compte à rebours dans l'app). */
    public static function deadline(Transaction $tx): ?\Illuminate\Support\Carbon
    {
        if ($tx->status !== 'processing' || $tx->stage !== 'awaiting_source' || ! $tx->created_at) {
            return null;
        }
        return $tx->created_at->copy()->addSeconds(self::timeoutSeconds());
    }

    /** Expire la transaction si son délai de validation est dépassé. Retourne true si expirée. */
    public function expireIfStale(Transaction $tx): bool
    {
        $deadline = self::deadline($tx);
        if (! $deadline || $deadline->isFuture()) {
            return false;
        }

        // Dernière vérification chez PEEX avant de conclure
        foreach ($tx->peexRequests()->whereNull('finalized_at')->get() as $req) {
            try {
                $this->handler->refresh($req);
            } catch (\Throwable $e) {
                Log::info('PEEX : dernière vérification impossible avant expiration', ['reference' => $tx->reference, 'error' => $e->getMessage()]);
            }
        }
        $tx->refresh();
        if ($tx->status !== 'processing' || $tx->stage !== 'awaiting_source') {
            return false; // confirmée (ou refusée) entre-temps
        }

        $meta = $tx->meta ?? [];
        $tx->forceFill(['meta' => $meta + ['validation_timeout' => now()->toIso8601String()]])->saveQuietly();
        $this->switch->onSourceFailed($tx->fresh(), self::REASON);

        return true;
    }

    /** Planifié chaque minute (flashpay:maintenance). */
    public function expireDue(): int
    {
        $n = 0;
        Transaction::where('status', 'processing')->where('stage', 'awaiting_source')
            ->where('created_at', '<', now()->subSeconds(self::timeoutSeconds()))
            ->orderBy('id')->limit(100)->get()
            ->each(function (Transaction $tx) use (&$n) {
                $n += $this->expireIfStale($tx) ? 1 : 0;
            });

        return $n;
    }

    /**
     * Collecte confirmée par PEEX APRÈS l'expiration : on rouvre la transaction
     * pour créditer le client (ou verser au bénéficiaire) — l'argent a bien été débité.
     */
    public function reopenAfterLateSuccess(Transaction $tx): Transaction
    {
        $tx->refresh();
        if ($tx->status !== 'failed' || empty(($tx->meta ?? [])['validation_timeout'])) {
            return $tx;
        }
        Log::warning('PEEX : validation tardive après expiration — transaction rouverte', ['reference' => $tx->reference]);
        $tx->forceFill([
            'status' => 'processing',
            'stage' => 'awaiting_source',
            'failure_reason' => null,
            'meta' => ($tx->meta ?? []) + ['late_validation' => now()->toIso8601String()],
        ])->saveQuietly();

        return $this->switch->onSourceConfirmed($tx->fresh());
    }
}
