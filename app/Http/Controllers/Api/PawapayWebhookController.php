<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitRequest;
use App\Services\Remit\RemitStatusHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Callback pawaPay (dépôts, versements, remittances) : {APP_URL}/api/webhooks/pawapay,
 * à saisir dans le tableau de bord pawaPay (Settings › Callback URLs).
 *
 * Le contenu du callback n'est JAMAIS cru : on n'en garde que l'identifiant
 * (depositId / payoutId / remittanceId) et le statut est relu auprès de l'API pawaPay
 * avant toute validation ou tout remboursement. Réponse 200 rapide (pawaPay relance sinon) ;
 * traitement idempotent (un rappel en double ne change rien).
 */
class PawapayWebhookController extends Controller
{
    public function __invoke(Request $request, RemitStatusHandler $handler)
    {
        $id = (string) ($request->input('depositId') ?? $request->input('payoutId') ?? $request->input('remittanceId') ?? '');
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return response()->json(['ok' => true]);
        }
        $req = RemitRequest::whereIn('partner', ['pawapay', 'pawapay_momo'])->where('partner_ref', $id)->first();
        if (! $req || $req->finalized_at) {
            return response()->json(['ok' => true]);
        }
        if (Cache::add('pawapay:callback:' . $req->id, 1, 5)) {
            try {
                $handler->refresh($req);
            } catch (\Throwable $e) {
                // remit:sync reprendra la vérification
                Log::warning('pawaPay : callback non traité', ['reference' => $req->reference, 'error' => $e->getMessage()]);
            }
        }
        return response()->json(['ok' => true]);
    }
}
