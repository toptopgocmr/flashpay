<?php

namespace App\Http\Controllers\Api;

use App\Models\RemitRequest;
use App\Services\Maishapay\MaishapayClient;
use App\Services\Remit\RemitStatusHandler;
use Illuminate\Http\Request;

/** Console › Intégrations › Passerelle MaishaPay : configuration, pays, test de connexion, opérations. */
class MaishapayAdminController extends \App\Http\Controllers\Controller
{
    public function overview(MaishapayClient $client)
    {
        $corr = collect(app(\App\Services\Peex\PeexCorridors::class)->catalog());
        $q = RemitRequest::where('partner', 'maishapay');
        $labels = ['deposit' => 'Collecte mobile', 'payout' => 'Versement', 'refund' => 'Remboursement', 'checkout' => 'Carte',
            'airtime' => 'Crédit téléphonique', 'paytv' => 'Abonnement TV', 'utility' => 'Facture'];

        return response()->json([
            'configured' => $client->enabled(),
            'enabled_flag' => (bool) config('flashpay.maishapay.enabled'),
            'public_key' => ($k = (string) config('flashpay.maishapay.public_key')) ? substr($k, 0, 10) . '…' . substr($k, -4) : null,
            'secret_key' => (bool) config('flashpay.maishapay.secret_key'),
            'rail_enabled' => (bool) config('flashpay.rails.maishapay.enabled'),
            'sandbox' => $client->sandbox(),
            'base_url' => (string) config('flashpay.maishapay.base_url'),
            'dashboard_url' => 'https://marchand.maishapay.online',
            'docs_url' => 'https://docs.maishapay.net',
            'callback_url' => $client->callbackUrl(),
            'webhook_signed' => (bool) config('flashpay.maishapay.webhook_secret'),
            'card' => config('payment_methods.card_driver') === 'maishapay',
            'bills' => app(\App\Services\Bills\BillPaymentService::class)->available(),
            'paths' => (array) config('flashpay.maishapay.paths', []),
            'countries' => $client->countries(),
            'countries_collect' => $corr->where('collect_partner', 'maishapay')->pluck('country')->values(),
            'countries_payout' => $corr->where('payout_partner', 'maishapay')->pluck('country')->values(),
            'stats' => [
                'deposit' => (clone $q)->whereIn('method', ['deposit', 'checkout'])->where('created_at', '>=', now()->subDays(30))->count(),
                'payout' => (clone $q)->whereIn('method', ['payout', 'refund'])->where('created_at', '>=', now()->subDays(30))->count(),
                'services' => (clone $q)->whereIn('method', ['airtime', 'paytv', 'utility'])->where('created_at', '>=', now()->subDays(30))->count(),
                'pending' => (clone $q)->whereNull('finalized_at')->count(),
                'failed' => (clone $q)->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'requests' => (clone $q)->with('transaction:id,reference,amount,fee,currency,destination_amount,destination_currency')
                ->latest('id')->limit(40)->get()->map(fn ($r) => [
                    'id' => $r->id, 'created_at' => $r->created_at, 'method' => $r->method, 'method_label' => $labels[$r->method] ?? $r->method,
                    'reference' => $r->reference, 'partner_ref' => $r->partner_ref, 'status' => $r->status, 'raw_status' => $r->raw_status,
                    'message' => $r->message, 'final' => (bool) $r->finalized_at,
                    'transaction' => $r->transaction ? ['id' => $r->transaction->id, 'reference' => $r->transaction->reference] : null,
                    'amount' => $r->transaction ? (in_array($r->method, ['deposit', 'checkout', 'refund'], true) ? $r->transaction->amount + ($r->method === 'payout' ? 0 : $r->transaction->fee) : ($r->transaction->destination_amount ?? $r->transaction->amount)) : null,
                    'currency' => $r->transaction?->currency,
                ]),
        ]);
    }

    /**
     * Test de connexion : interroge transaction/rest/v2/check sur une référence inexistante.
     * Clés refusées → erreur explicite ; clés acceptées → « opération introuvable » (connexion OK).
     */
    public function test(MaishapayClient $client)
    {
        if (! $client->enabled()) {
            return response()->json(['ok' => false, 'message' => 'MaishaPay non configuré : MAISHAPAY_ENABLED, MAISHAPAY_PUBLIC_KEY, MAISHAPAY_SECRET_KEY.']);
        }
        config(['flashpay.maishapay.timeout' => 10]);
        $t = microtime(true);
        try {
            $r = $client->check('FP-TEST-' . now()->format('YmdHis'), true);
            return response()->json(['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000), 'message' => 'Connexion MaishaPay OK (' . ($client->sandbox() ? 'sandbox' : 'production') . ').',
                'raw_status' => $r['raw_status'], 'response' => $r['raw']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'ms' => (int) round((microtime(true) - $t) * 1000), 'message' => mb_substr($e->getMessage(), 0, 300),
                'response' => $e instanceof \App\Services\Remit\RemitException ? $e->response : null]);
        }
    }

    public function refresh(RemitRequest $remitRequest, RemitStatusHandler $handler)
    {
        abort_unless($remitRequest->partner === 'maishapay', 404);
        $req = $handler->refresh($remitRequest);
        return response()->json(['status' => $req->status, 'raw_status' => $req->raw_status, 'message' => $req->message, 'final' => (bool) $req->finalized_at]);
    }
}
