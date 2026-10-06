<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Transaction;
use App\Services\Peex\PendingTimeoutService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Une seule opération d'argent à la fois par utilisateur (envoi, recharge,
 * paiement, retrait…) :
 *  - deux requêtes simultanées → la 2e est refusée (verrou) ;
 *  - une opération encore « en cours » (validation sur le téléphone, page carte,
 *    versement en attente) bloque la suivante pendant FLASHPAY_SINGLE_OPERATION_MINUTES.
 * Réponse 409 « operation_in_progress » + l'opération en cours, que l'app peut rouvrir.
 */
class SingleOperation
{
    public static function pendingOf(int $userId, ?int $minutes = null): ?Transaction
    {
        $minutes ??= (int) config('flashpay.single_operation_minutes', 10);
        $tx = Transaction::where('initiated_by', $userId)->where('status', 'processing')
            // Bon de retrait en attente de l'agent / du GAB : ce n'est pas une opération en cours
            ->where(fn ($q) => $q->whereNull('stage')->orWhere('stage', '!=', 'awaiting_pickup'))
            ->where('created_at', '>=', now()->subMinutes(max(1, $minutes)))
            ->latest('id')->first();
        if ($tx) {
            // Validation sur le téléphone expirée : l'opération est conclue avant de bloquer
            try {
                app(PendingTimeoutService::class)->expireIfStale($tx);
                $tx->refresh();
            } catch (\Throwable) {
            }
        }
        return $tx && $tx->status === 'processing' ? $tx : null;
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || $user->hasAnyRole(['super_admin', 'support']) || ! config('flashpay.single_operation', true)) {
            return $next($request);
        }

        $lock = Cache::lock("single-op:user:{$user->id}", 120);
        if (! $lock->get()) {
            return response()->json([
                'code' => 'operation_in_progress',
                'message' => 'Une opération est déjà en cours de traitement. Patientez quelques secondes.',
            ], 409);
        }
        try {
            if ($tx = self::pendingOf($user->id)) {
                return response()->json([
                    'code' => 'operation_in_progress',
                    'message' => 'Vous avez déjà une opération en cours (' . $tx->reference . '). Attendez qu\'elle soit terminée avant d\'en lancer une autre.',
                    'transaction' => app(PaymentController::class)->txPayload($tx),
                ], 409);
            }
            return $next($request);
        } finally {
            $lock->release();
        }
    }
}
