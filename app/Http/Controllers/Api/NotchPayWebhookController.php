<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotchPay\NotchPayStatusHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Notch Pay : POST {APP_URL}/api/webhooks/notchpay
 * (Business Suite › Settings › Webhooks — événements payment.* et transfer.*)
 *
 * Sécurité :
 *  1. Signature « x-notch-signature » = HMAC-SHA256 du corps brut avec NOTCHPAY_HASH_KEY ;
 *  2. IP autorisées optionnelles : NOTCHPAY_WEBHOOK_IPS.
 * Et surtout : le statut reçu n'est jamais appliqué tel quel — FlashPay
 * réinterroge l'API Notch Pay avant de valider ou de rembourser.
 */
class NotchPayWebhookController extends Controller
{
    public function __invoke(Request $request, NotchPayStatusHandler $handler)
    {
        $cfg = config('flashpay.notchpay');

        if ($ips = array_filter(array_map('trim', explode(',', (string) ($cfg['webhook_ips'] ?? ''))))) {
            if (! in_array($request->ip(), $ips, true)) {
                Log::warning('Webhook Notch Pay refusé : IP non autorisée', ['ip' => $request->ip()]);
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }
        $hash = (string) ($cfg['hash_key'] ?? '');
        if ($hash === '') {
            // Sans clé de hachage on ne peut pas authentifier l'appel : refusé (Notch Pay réessaiera)
            Log::error('Webhook Notch Pay refusé : NOTCHPAY_HASH_KEY non configurée');
            return response()->json(['message' => 'Webhook not configured'], 503);
        }
        $sent = strtolower((string) $request->header('x-notch-signature'));
        $expected = hash_hmac('sha256', $request->getContent(), $hash);
        if ($sent === '' || ! hash_equals($expected, $sent)) {
            Log::warning('Webhook Notch Pay refusé : signature invalide', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $payload = $request->json()->all() ?: $request->all();
        try {
            $req = $handler->onCallback($payload);
        } catch (\Throwable $e) {
            Log::error('Webhook Notch Pay : traitement impossible', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'retry'], 500);
        }

        Log::info('Webhook Notch Pay reçu', ['type' => $payload['type'] ?? $payload['event'] ?? null, 'reference' => $req?->reference, 'status' => $req?->status]);
        return response()->json(['message' => 'ok', 'known' => (bool) $req, 'status' => $req?->status]);
    }
}
