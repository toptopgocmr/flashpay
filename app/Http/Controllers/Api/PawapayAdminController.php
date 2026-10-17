<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitRequest;
use App\Services\Remit\PawapayClient;
use App\Services\Remit\RemitStatusHandler;
use Illuminate\Http\Request;

/** Console › Intégrations › Passerelle PawaPay : configuration, couverture, soldes, test, opérations. */
class PawapayAdminController extends Controller
{
    public function overview(Request $request, PawapayClient $client)
    {
        $base = (string) config('flashpay.pawapay.base_url');
        $out = [
            'configured' => $client->enabled(),
            'enabled_flag' => (bool) config('flashpay.pawapay.enabled'),
            'token' => (bool) config('flashpay.pawapay.token'),
            'rail_enabled' => (bool) config('flashpay.rails.pawapay_momo.enabled'),
            'sandbox' => $client->sandbox(),
            'base_url' => $base,
            'dashboard_url' => $client->sandbox() ? 'https://dashboard.sandbox.pawapay.io' : 'https://dashboard.pawapay.io',
            'callback_url' => url('/api/webhooks/pawapay'),
            'mode' => $client->mode(),
            'check_balance' => (bool) config('flashpay.pawapay.check_balance', true),
            'coverage' => null, 'coverage_error' => null, 'balances' => null, 'balances_error' => null,
        ];
        if ($client->enabled()) {
            // Lectures console : 10 s max chacune (jamais la limite PHP de 30 s)
            config(['flashpay.pawapay.timeout' => min(10, (int) config('flashpay.pawapay.timeout', 40))]);
            try {
                $out['coverage'] = $client->coverage($request->boolean('refresh'));
                $out['coverage_synced_at'] = cache('pawapay:coverage:synced_at');
            } catch (\Throwable $e) {
                $out['coverage_error'] = mb_substr($e->getMessage(), 0, 250);
            }
            try {
                $out['balances'] = $client->balances();
            } catch (\Throwable $e) {
                $out['balances_error'] = mb_substr($e->getMessage(), 0, 250);
            }
        }
        // Pays confiés à PawaPay dans Pays & change
        $corr = collect(app(\App\Services\Peex\PeexCorridors::class)->catalog());
        $out['countries_collect'] = $corr->where('collect_partner', 'pawapay')->pluck('country')->values();
        $out['countries_payout'] = $corr->where('payout_partner', 'pawapay')->pluck('country')->values();

        $q = RemitRequest::whereIn('partner', ['pawapay', 'pawapay_momo']);
        $out['stats'] = [
            'deposit' => (clone $q)->where('method', 'deposit')->where('created_at', '>=', now()->subDays(30))->count(),
            'payout' => (clone $q)->where('method', '!=', 'deposit')->where('created_at', '>=', now()->subDays(30))->count(),
            'pending' => (clone $q)->whereNull('finalized_at')->count(),
            'failed' => (clone $q)->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count(),
        ];
        $out['requests'] = (clone $q)->with('transaction:id,reference,amount,currency,destination_amount,destination_currency')
            ->latest('id')->limit(30)->get()->map(fn ($r) => [
                'id' => $r->id, 'created_at' => $r->created_at, 'partner' => $r->partner, 'method' => $r->method,
                'reference' => $r->reference, 'partner_ref' => $r->partner_ref, 'status' => $r->status, 'raw_status' => $r->raw_status,
                'message' => $r->message, 'final' => (bool) $r->finalized_at,
                'transaction' => $r->transaction ? ['id' => $r->transaction->id, 'reference' => $r->transaction->reference] : null,
                'amount' => $r->transaction ? ($r->method === 'deposit' ? $r->transaction->amount : ($r->transaction->destination_amount ?? $r->transaction->amount)) : null,
                'currency' => $r->transaction ? ($r->method === 'deposit' ? $r->transaction->currency : ($r->transaction->destination_currency ?: $r->transaction->currency)) : null,
            ]);

        return response()->json($out);
    }

    /** Test sans mouvement d'argent : opérateur reconnu pour un numéro (POST /v2/predict-provider). */
    public function testNumber(Request $request, PawapayClient $client)
    {
        $v = $request->validate(['phone' => 'required|string|max:20']);
        try {
            $r = $client->predictProvider($v['phone']);
            return response()->json(['ok' => true] + $r);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Statut relu chez PawaPay (et appliqué à la transaction). */
    public function refresh(RemitRequest $remitRequest, RemitStatusHandler $handler)
    {
        abort_unless(in_array($remitRequest->partner, ['pawapay', 'pawapay_momo'], true), 404);
        try {
            $r = $handler->refresh($remitRequest);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['status' => $r->status, 'raw_status' => $r->raw_status, 'final' => (bool) $r->finalized_at, 'message' => $r->message]);
    }
}
