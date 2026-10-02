<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Digitwace\DigitwaceStatusHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Digitwace / WacePay : POST {APP_URL}/api/webhooks/digitwace
 *
 * Sécurité (cumulative, selon ce qui est configuré) :
 *  1. Signature HMAC-SHA256 du corps brut avec DIGITWACE_WEBHOOK_SECRET
 *     (en-tête DIGITWACE_SIGNATURE_HEADER, « X-Wace-Signature » par défaut ;
 *     accepte la valeur hex ou « sha256=<hex> ») ;
 *  2. Jeton partagé optionnel dans l'URL : ?token=DIGITWACE_WEBHOOK_TOKEN ;
 *  3. IP autorisées optionnelles : DIGITWACE_WEBHOOK_IPS.
 * Et surtout : le statut reçu n'est jamais appliqué tel quel — FlashPay
 * réinterroge l'API WacePay avant de valider ou de rembourser.
 */
class DigitwaceWebhookController extends Controller
{
    public function __invoke(Request $request, DigitwaceStatusHandler $handler)
    {
        $cfg = config('flashpay.digitwace');

        if ($ips = array_filter(array_map('trim', explode(',', (string) ($cfg['webhook_ips'] ?? ''))))) {
            if (! in_array($request->ip(), $ips, true)) {
                Log::warning('Webhook WacePay refusé : IP non autorisée', ['ip' => $request->ip()]);
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }
        if ($token = (string) ($cfg['webhook_token'] ?? '')) {
            if (! hash_equals($token, (string) $request->query('token'))) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }
        }
        if ($secret = (string) ($cfg['webhook_secret'] ?? '')) {
            $sent = (string) $request->header($cfg['signature_header'] ?? 'X-Wace-Signature');
            $sent = str_starts_with($sent, 'sha256=') ? substr($sent, 7) : $sent;
            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            if (! $sent || ! hash_equals($expected, strtolower($sent))) {
                Log::warning('Webhook WacePay refusé : signature invalide', ['ip' => $request->ip()]);
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->json()->all() ?: $request->all();
        $items = array_is_list($payload) ? $payload : [$payload];
        $done = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            try {
                $req = $handler->onCallback($item);
                $done[] = ['reference' => $req?->reference, 'known' => (bool) $req, 'status' => $req?->status];
            } catch (\Throwable $e) {
                Log::error('Webhook WacePay : traitement impossible', ['error' => $e->getMessage()]);
                $done[] = ['known' => null, 'error' => 'retry'];
            }
        }

        Log::info('Webhook WacePay reçu', ['count' => count($items)]);
        return response()->json(['message' => 'ok', 'processed' => $done]);
    }
}
