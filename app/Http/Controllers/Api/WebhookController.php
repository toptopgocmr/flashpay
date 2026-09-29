<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Peex\PeexStatusHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callbacks PEEX (doc : https://peex-api-docs.peexit.com/notifications)
 *  - POST, sécurisé en HTTP Basic Auth (PEEX_CALLBACK_USERNAME / PASSWORD)
 *  - corps = TABLEAU des transactions non encore transmises
 *    [{id, amount, status, currency, track_id, payment_proof, message, ...}]
 *
 * URLs à communiquer à PEEX (une par service) :
 *   collecte      : {APP_URL}/api/webhooks/peex/collect
 *   disbursement  : {APP_URL}/api/webhooks/peex/disbursement
 *   remittance    : {APP_URL}/api/webhooks/peex/remittance
 */
class WebhookController extends Controller
{
    public function peex(Request $request, PeexStatusHandler $handler, ?string $service = null)
    {
        $user = (string) config('flashpay.peex.callback_username');
        $pass = (string) config('flashpay.peex.callback_password');

        if (! hash_equals($user, (string) $request->getUser()) || ! hash_equals($pass, (string) $request->getPassword())) {
            Log::warning('Callback PEEX refusé : Basic Auth invalide', ['ip' => $request->ip(), 'service' => $service]);
            return response()->json(['message' => 'Unauthorized'], 401)
                ->header('WWW-Authenticate', 'Basic realm="FlashPay PEEX"');
        }

        $payload = $request->json()->all();
        $items = array_is_list($payload) ? $payload : [$payload['data'] ?? $payload['request'] ?? $payload];

        Log::info('Callback PEEX reçu', ['service' => $service, 'count' => count($items)]);

        $processed = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $req = $handler->applyCallbackItem($item, $service);
            $processed[] = [
                'track_id' => $item['track_id'] ?? null,
                'known' => (bool) $req,
                'status' => $req?->status,
            ];
        }

        return response()->json(['message' => 'ok', 'processed' => $processed]);
    }
}
