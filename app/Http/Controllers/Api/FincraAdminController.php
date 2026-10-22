<?php

namespace App\Http\Controllers\Api;

use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Services\Fincra\FincraClient;
use App\Services\Remit\RemitStatusHandler;
use Illuminate\Http\Request;

/**
 * Console › Intégrations › Passerelle Fincra : configuration, pays (national + international),
 * soldes, opérateurs d'un pays, test de connexion, opérations.
 * App : saisie du code OTP d'une collecte Fincra (data.auth_model = OTP).
 */
class FincraAdminController extends \App\Http\Controllers\Controller
{
    public const LABELS = ['deposit' => 'Collecte mobile', 'payout' => 'Versement', 'refund' => 'Remboursement', 'wallet' => 'International · mobile', 'bank' => 'International · banque'];

    public function overview(FincraClient $client)
    {
        $corr = collect(app(\App\Services\Peex\PeexCorridors::class)->catalog());
        $q = RemitRequest::whereIn('partner', ['fincra', 'fincra_remit']);
        $key = (string) config('flashpay.fincra.secret_key');

        return response()->json([
            'configured' => $client->enabled(),
            'enabled_flag' => (bool) config('flashpay.fincra.enabled'),
            'secret_key' => $key ? substr($key, 0, 4) . '…' . substr($key, -4) : null,
            'public_key' => (bool) config('flashpay.fincra.public_key'),
            'business_id' => ($b = $client->businessId()) ? substr($b, 0, 6) . '…' . substr($b, -4) : null,
            'rail_enabled' => (bool) config('flashpay.rails.fincra.enabled'),
            'remit_enabled' => (bool) config('flashpay.rails.fincra_remit.enabled'),
            'sandbox' => $client->sandbox(),
            'base_url' => (string) config('flashpay.fincra.base_url'),
            'dashboard_url' => $client->sandbox() ? 'https://sandbox.fincra.com' : 'https://app.fincra.com',
            'docs_url' => 'https://docs.fincra.com/docs/getting-started',
            'webhook_url' => $client->webhookUrl(),
            'webhook_signed' => (bool) config('flashpay.fincra.webhook_secret'),
            'remit_source_currency' => (string) config('flashpay.fincra.remit_source_currency') ?: null,
            'fee_bearer' => (string) config('flashpay.fincra.fee_bearer'),
            'check_balance' => (bool) config('flashpay.fincra.check_balance', true),
            'countries' => $client->countries(),
            'remit_countries' => $client->remitCountries(),
            'countries_collect' => $corr->where('collect_partner', 'fincra')->pluck('country')->values(),
            'countries_payout' => $corr->where('payout_partner', 'fincra')->pluck('country')->values(),
            'countries_remit' => collect(\App\Services\Remit\RemitCountries::all())->keys()
                ->filter(fn ($iso) => \App\Services\Remit\RemitCountries::partnerFor($iso) === 'fincra_remit')->values(),
            'endpoints' => [
                ['POST', '/checkout/charges', 'Collecte mobile money (Direct Charge)'],
                ['GET', '/checkout/charges/verify/{reference}', 'Statut d\'une collecte'],
                ['POST', '/disbursements/payouts', 'Versement mobile money / banque (national et international)'],
                ['GET', '/disbursements/payouts/customer-reference/{ref}', 'Statut d\'un versement'],
                ['POST', '/quotes/generate', 'Devis de conversion (remittance multi-devises)'],
                ['GET', '/core/banks', 'Opérateurs mobile money et banques d\'un pays'],
                ['GET', '/wallets', 'Soldes'],
            ],
            'stats' => [
                'deposit' => (clone $q)->where('method', 'deposit')->where('created_at', '>=', now()->subDays(30))->count(),
                'payout' => (clone $q)->whereIn('method', ['payout', 'refund'])->where('created_at', '>=', now()->subDays(30))->count(),
                'remit' => (clone $q)->where('partner', 'fincra_remit')->where('created_at', '>=', now()->subDays(30))->count(),
                'pending' => (clone $q)->whereNull('finalized_at')->count(),
                'failed' => (clone $q)->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'requests' => (clone $q)->with('transaction:id,reference,amount,fee,currency,destination_amount,destination_currency')
                ->latest('id')->limit(40)->get()->map(fn ($r) => [
                    'id' => $r->id, 'created_at' => $r->created_at, 'partner' => $r->partner, 'method' => $r->method,
                    'method_label' => self::LABELS[$r->method] ?? ($r->partner === 'fincra_remit' ? 'International' : $r->method),
                    'reference' => $r->reference, 'partner_ref' => $r->partner_ref, 'status' => $r->status, 'raw_status' => $r->raw_status,
                    'message' => $r->message, 'final' => (bool) $r->finalized_at,
                    'transaction' => $r->transaction ? ['id' => $r->transaction->id, 'reference' => $r->transaction->reference] : null,
                    'amount' => $r->transaction ? (in_array($r->method, ['deposit', 'refund'], true)
                        ? $r->transaction->amount + $r->transaction->fee
                        : ($r->transaction->destination_amount ?? $r->transaction->amount)) : null,
                    'currency' => $r->transaction ? (in_array($r->method, ['deposit', 'refund'], true) ? $r->transaction->currency : ($r->transaction->destination_currency ?: $r->transaction->currency)) : null,
                ]),
        ]);
    }

    /** Test de connexion : lecture des soldes (GET /wallets). */
    public function test(FincraClient $client)
    {
        if (! $client->enabled()) {
            return response()->json(['ok' => false, 'message' => 'Fincra non configuré : FINCRA_ENABLED, FINCRA_SECRET_KEY, FINCRA_BUSINESS_ID.']);
        }
        config(['flashpay.fincra.timeout' => 10]);
        $t = microtime(true);
        try {
            $b = $client->balances();
            return response()->json(['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000),
                'message' => 'Connexion Fincra OK (' . ($client->sandbox() ? 'sandbox' : 'production') . ') — ' . count($b) . ' solde(s).', 'balances' => $b]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'ms' => (int) round((microtime(true) - $t) * 1000), 'message' => mb_substr($e->getMessage(), 0, 300),
                'response' => $e instanceof \App\Services\Remit\RemitException ? $e->response : null]);
        }
    }

    public function balances(FincraClient $client)
    {
        $base = ['configured' => $client->enabled(), 'sandbox' => $client->sandbox(), 'checked_at' => now()->toIso8601String()];
        if (! $client->enabled()) {
            return response()->json($base + ['ok' => false, 'error' => 'Fincra non configuré.', 'accounts' => []]);
        }
        try {
            return response()->json($base + ['ok' => true, 'accounts' => $client->balances()]);
        } catch (\Throwable $e) {
            return response()->json($base + ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300), 'accounts' => []]);
        }
    }

    /** Opérateurs mobile money et banques Fincra d'un pays (codes à utiliser). */
    public function institutions(Request $request, FincraClient $client, string $iso)
    {
        $iso = strtoupper($iso);
        $out = [];
        foreach (['mobile_money_wallet', 'bank_account'] as $d) {
            try {
                $out[$d] = $client->institutions($iso, $d, $request->query('currency'));
            } catch (\Throwable $e) {
                $out[$d] = ['error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }
        return response()->json(['country' => $iso] + $out);
    }

    public function refresh(RemitRequest $remitRequest, RemitStatusHandler $handler)
    {
        abort_unless(in_array($remitRequest->partner, ['fincra', 'fincra_remit'], true), 404);
        $req = $handler->refresh($remitRequest);
        return response()->json(['status' => $req->status, 'raw_status' => $req->raw_status, 'message' => $req->message, 'final' => (bool) $req->finalized_at]);
    }

    /** App : code OTP reçu par SMS pour valider une collecte Fincra (ex. MTN Ghana). */
    public function otp(Request $request, Transaction $transaction, FincraClient $client)
    {
        abort_unless($transaction->initiated_by === $request->user()->id, 403);
        $v = $request->validate(['otp' => 'required|string|min:3|max:12']);
        $req = RemitRequest::where('transaction_id', $transaction->id)->where('partner', 'fincra')->where('method', 'deposit')->whereNull('finalized_at')->latest('id')->first();
        $chargeId = (string) ($transaction->meta['fincra_charge_id'] ?? '');
        if (! $req || $chargeId === '') {
            return response()->json(['message' => 'Aucune validation par code en attente pour cette opération.'], 422);
        }
        try {
            $client->authorizeCharge($chargeId, $v['otp']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Code refusé : ' . mb_substr($e->getMessage(), 0, 150)], 422);
        }
        $transaction->forceFill(['meta' => array_merge($transaction->meta ?? [], ['fincra_otp_required' => false])])->save();
        return response()->json(['ok' => true, 'message' => 'Code envoyé, validation en cours.']);
    }
}
