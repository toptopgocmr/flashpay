<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitRequest;
use App\Services\Fincra\FincraClient;
use App\Services\Remit\RemitStatusHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks Fincra (charge.successful, payout.successful, payout.failed…) : {APP_URL}/api/webhooks/fincra,
 * à déclarer dans le portail Fincra (Settings › Webhooks).
 *
 * Signature : en-tête « signature » = HMAC-SHA512 (hex) du corps avec le webhook secret
 * (FINCRA_WEBHOOK_SECRET) — notification ignorée si elle ne correspond pas.
 * Le contenu n'est JAMAIS cru : on n'en garde que les références, puis le statut est relu auprès
 * de l'API Fincra avant toute validation ou tout remboursement. Réponse 200 rapide, traitement idempotent.
 */
class FincraWebhookController extends Controller
{
    public function __invoke(Request $request, RemitStatusHandler $handler, FincraClient $client)
    {
        if ($client->validSignature((string) $request->getContent(), $request->header('signature')) === false) {
            Log::warning('Fincra : notification à la signature invalide ignorée', ['event' => $request->input('event')]);
            return response()->json(['ok' => true]);
        }
        $p = $request->all();
        $ids = array_values(array_unique(array_filter(array_map('strval', array_filter([
            data_get($p, 'data.customerReference'), data_get($p, 'data.merchantReference'), data_get($p, 'data.reference'),
            data_get($p, 'data.chargeReference'), data_get($p, 'data.id'), data_get($p, 'customerReference'), data_get($p, 'reference'),
        ], 'is_scalar')))));
        $req = $ids ? RemitRequest::whereIn('partner', ['fincra', 'fincra_remit'])
            ->where(fn ($q) => $q->whereIn('reference', $ids)->orWhereIn('partner_ref', $ids))->first() : null;
        if (! $req) {
            Log::info('Fincra : notification sans opération OfficePay correspondante', ['event' => $request->input('event'), 'ids' => $ids]);
            return response()->json(['ok' => true]);
        }
        if (! $req->finalized_at && Cache::add('fincra:callback:' . $req->id, 1, 5)) {
            try {
                $handler->refresh($req);
            } catch (\Throwable $e) {
                Log::warning('Fincra : notification non traitée', ['reference' => $req->reference, 'error' => $e->getMessage()]);
            }
        }
        return response()->json(['ok' => true]);
    }
}
