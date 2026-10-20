<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitRequest;
use App\Services\Maishapay\MaishapayClient;
use App\Services\Remit\RemitStatusHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Notifications MaishaPay (collecte, versement B2C, carte, services) : {APP_URL}/api/webhooks/maishapay
 * (POST JSON, ou GET ?status=&description=&transactionRefId=&operatorRefId= pour la carte).
 *
 * Le contenu n'est JAMAIS cru (la doc MaishaPay le rappelle : les notifications peuvent être
 * altérées) : on n'en garde que les identifiants, puis le statut est relu par
 * transaction/rest/v2/check avant toute validation ou tout remboursement. Réponse 200 rapide,
 * traitement idempotent. Signature X-MaishaPay-Signature vérifiée si MAISHAPAY_WEBHOOK_SECRET est défini.
 */
class MaishapayWebhookController extends Controller
{
    public function __invoke(Request $request, RemitStatusHandler $handler, MaishapayClient $client)
    {
        if ($client->validSignature((string) $request->getContent(), $request->header('X-MaishaPay-Signature')) === false) {
            Log::warning('MaishaPay : notification à la signature invalide ignorée');
            return response()->json(['ok' => true]);
        }
        $p = $request->all();
        $ids = array_values(array_unique(array_filter(array_map('strval', array_filter([
            data_get($p, 'transactionId'), data_get($p, 'data.transactionId'), data_get($p, 'data.transaction_id'), data_get($p, 'transaction_id'),
            data_get($p, 'transactionRefId'), data_get($p, 'originatingTransactionId'), data_get($p, 'data.originatingTransactionId'),
            data_get($p, 'transactionReference'), data_get($p, 'reference'), data_get($p, 'data.reference'),
        ], 'is_scalar')))));
        $req = $ids ? RemitRequest::where('partner', 'maishapay')
            ->where(fn ($q) => $q->whereIn('partner_ref', $ids)->orWhereIn('reference', $ids))->first() : null;
        if (! $req) {
            Log::info('MaishaPay : notification sans opération FlashPay correspondante', ['ids' => $ids]);
            return response()->json(['ok' => true]);
        }
        if (! $req->finalized_at && Cache::add('maishapay:callback:' . $req->id, 1, 5)) {
            try {
                $handler->refresh($req);
            } catch (\Throwable $e) {
                Log::warning('MaishaPay : notification non traitée', ['reference' => $req->reference, 'error' => $e->getMessage()]);
            }
        }
        return response()->json(['ok' => true]);
    }
}
