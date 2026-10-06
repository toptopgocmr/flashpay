<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DigitwaceRequest;
use App\Services\Digitwace\DigitwaceClient;
use App\Services\Digitwace\DigitwaceException;
use App\Services\Digitwace\DigitwaceStatusHandler;
use App\Services\Digitwace\WacepayBalanceService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Console › Passerelle WacePay (API Partenaire Digitwace). */
class WacepayAdminController extends Controller
{
    public function __construct(protected DigitwaceClient $client)
    {
    }

    public function overview()
    {
        $pub = (string) config('flashpay.digitwace.public_key');
        $sandbox = (bool) config('flashpay.digitwace.sandbox', true);
        $requests = DigitwaceRequest::with('transaction:id,reference,amount,destination_amount,currency,destination_currency')
            ->latest('id')->limit(40)->get()->map(fn ($r) => $this->present($r));
        $since = now()->subDays(30);

        return response()->json([
            'enabled' => (bool) config('flashpay.digitwace.enabled'),
            'configured' => $this->client->enabled(),
            'sandbox' => $sandbox,
            'api' => $this->client->partner() ? 'partner' : 'legacy',
            'base_url' => rtrim((string) (DigitwaceClient::endpointOverride()['base_url'] ?? config('flashpay.digitwace.base_url')), '/') . '/',
            'dashboard_url' => $sandbox ? 'https://sandbox-payin.wacepay.io/dashboard/developers' : 'https://app.wacepay.io/dashboard/developers',
            'public_key' => $pub ? substr($pub, 0, 10) . '…' . substr($pub, -6) : null,
            'private_key' => (bool) config('flashpay.digitwace.private_key'),
            'server_ip' => WacepayBalanceService::serverIp(),
            'webhook' => $this->client->callbackUrl(),
            'webhook_signed' => (bool) config('flashpay.digitwace.webhook_secret'),
            'endpoints' => $this->client->partner() ? DigitwaceClient::PARTNER_PATHS : null,
            'stats' => [
                'payin' => DigitwaceRequest::where('operation', 'payin')->where('created_at', '>=', $since)->count(),
                'payout' => DigitwaceRequest::where('operation', 'payout')->where('created_at', '>=', $since)->count(),
                'pending' => DigitwaceRequest::whereNull('finalized_at')->whereIn('status', ['new', 'pending'])->count(),
                'failed' => DigitwaceRequest::where('status', 'failed')->where('created_at', '>=', $since)->count(),
            ],
            'requests' => $requests,
        ]);
    }

    /** Services de collecte (wp-subscription-key) et de versement (payoutSubscriptionId), lus en direct. */
    public function services()
    {
        if (! $this->client->enabled()) {
            return response()->json(['message' => 'WacePay n\'est pas configuré (DIGITWACE_ENABLED, clés).'], 422);
        }
        try {
            Cache::forget('digitwace:payers:all');
            $rows = $this->client->partner() ? $this->client->partnerServices() : $this->client->payerCodes();
        } catch (DigitwaceException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        $P = fn (array $s, array $k) => \App\Services\Digitwace\CoverageService::pick($s, $k);
        $out = collect($rows)->filter(fn ($s) => is_array($s))->map(fn ($s) => [
            'id' => (string) $P($s, ['payerCode', 'id']),
            'name' => (string) $P($s, ['payerName', 'name', 'serviceName', 'label']),
            'country' => $s['countryCode'] ?? \App\Services\Digitwace\CoverageService::countryOf($s),
            'currency' => ($c = $P($s, ['currency', 'currencyCode'])) ? strtoupper(substr($c, 0, 3)) : null,
            'operator' => ($o = $P($s, ['operatorCode', 'operator'])) ? strtoupper($o) : null,
            'payin' => (bool) ($s['payin'] ?? false),
            'payout' => (bool) ($s['payout'] ?? false),
            'status' => $P($s['raw_service'] ?? $s, ['status']),
        ])->values();
        Cache::put('wacepay:services', $out->all(), now()->addHours(6));

        return response()->json(['services' => $out]);
    }

    /** Collecte de test : demande de paiement envoyée au téléphone indiqué. */
    public function testPayin(Request $request)
    {
        $v = $request->validate([
            'service_id' => 'required|string|max:80',
            'amount' => 'required|integer|min:100',
            'phone' => 'required|string|max:20',
            'name' => 'nullable|string|max:100',
            'currency' => 'required|string|size:3',
            'country' => 'required|string|size:2',
            'operator' => 'nullable|string|max:30',
        ]);
        $svc = collect(Cache::get('wacepay:services', []))->firstWhere('id', $v['service_id']);
        $v['operator'] = ($v['operator'] ?? null) ?: ($svc['operator'] ?? null);
        $ref = 'WTEST-IN-' . strtoupper(Str::random(10));
        $req = DigitwaceRequest::create(['reference' => $ref, 'operation' => 'payin', 'status' => 'new']);
        try {
            $r = $this->client->payin($ref, $v['service_id'], $v['amount'], $v['currency'], $v['phone'], ($v['name'] ?? null) ?: 'Test FlashPay', $v['country'], $v['operator'] ?? null);
            $req->update(['wace_id' => $r['wace_id'], 'status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'last_response' => $r['raw'], 'last_checked_at' => now()]);
        } catch (DigitwaceException $e) {
            $req->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_response' => $e->response, 'finalized_at' => now()]);
        }
        Audit::log('digitwace.test_payin', null, ['reference' => $ref, 'amount' => $v['amount'], 'phone' => $v['phone']], $request->user()->id);

        return response()->json(['request' => $this->present($req->fresh())], 201);
    }

    /** Versement de test vers un numéro mobile money. */
    public function testPayout(Request $request)
    {
        $v = $request->validate([
            'service_id' => 'required|string|max:80',
            'amount' => 'required|integer|min:1',
            'phone' => 'required|string|max:20',
            'name' => 'nullable|string|max:100',
        ]);
        $ref = 'WTEST-OUT-' . strtoupper(Str::random(10));
        $req = DigitwaceRequest::create(['reference' => $ref, 'operation' => 'payout', 'status' => 'new']);
        try {
            $r = $this->client->payoutDirect($ref, $v['service_id'], $v['amount'], $v['phone'], $v['name'] ?? null);
            $req->update(['wace_id' => $r['wace_id'], 'status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'last_response' => $r['raw'], 'last_checked_at' => now()]);
        } catch (DigitwaceException $e) {
            $req->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_response' => $e->response, 'finalized_at' => now()]);
        }
        Audit::log('digitwace.test_payout', null, ['reference' => $ref, 'amount' => $v['amount'], 'phone' => $v['phone']], $request->user()->id);

        return response()->json(['request' => $this->present($req->fresh())], 201);
    }

    public function refresh(DigitwaceRequest $digitwaceRequest, DigitwaceStatusHandler $handler)
    {
        try {
            $req = $handler->refresh($digitwaceRequest);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        return response()->json(['request' => $this->present($req->load('transaction'))]);
    }

    protected function present(DigitwaceRequest $r): array
    {
        $tx = $r->transaction;
        $raw = (array) ($r->last_response ?? []);
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'operation' => $r->operation,
            'status' => $r->status,
            'raw_status' => $r->raw_status,
            'wace_id' => $r->wace_id,
            'amount' => $tx ? (int) ($r->operation === 'payout' ? ($tx->destination_amount ?? $tx->amount) : $tx->amount) : (data_get($raw, 'data.amount') ?? null),
            'currency' => $tx ? ($r->operation === 'payout' ? ($tx->destination_currency ?: $tx->currency) : $tx->currency) : (data_get($raw, 'data.currency') ?? null),
            'transaction' => $tx ? ['id' => $tx->id, 'reference' => $tx->reference] : null,
            'test' => str_starts_with((string) $r->reference, 'WTEST-'),
            'message' => $r->message,
            'final' => (bool) $r->finalized_at,
            'created_at' => $r->created_at?->toIso8601String(),
            'checked_at' => $r->last_checked_at?->toIso8601String(),
        ];
    }
}
