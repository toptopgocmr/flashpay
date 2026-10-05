<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Services\Peex\PeexClient;
use App\Services\Peex\PeexException;
use App\Services\Peex\PeexFlowService;
use App\Services\Peex\PeexGuard;
use App\Services\Peex\PeexStatusHandler;
use Illuminate\Http\Request;

/**
 * Supervision PEEX + banc de test sandbox (Super Admin).
 */
class PeexAdminController extends Controller
{
    public function overview(PeexClient $client)
    {
        $accounts = [];
        foreach (['collect' => 'collectMe', 'disbursement' => 'disbursementMe', 'remittance' => 'remittanceMe'] as $key => $method) {
            try {
                $accounts[$key] = ['ok' => true, 'data' => $client->{$method}()];
            } catch (PeexException $e) {
                $accounts[$key] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'sandbox' => $client->isSandbox(),
            'base_url' => $client->baseUrl(),
            // IP sortante du serveur : à faire autoriser par PEEX (pare-feu). Elle change à
            // chaque redéploiement Railway tant qu'aucune IP statique n'est configurée.
            'server_ip' => \Illuminate\Support\Facades\Cache::remember('server:egress_ip', now()->addMinutes(10), function () {
                try {
                    return trim(\Illuminate\Support\Facades\Http::timeout(5)->get('https://api.ipify.org')->body()) ?: null;
                } catch (\Throwable) {
                    return null;
                }
            }),
            'callback_urls' => [
                'collect' => url('/api/webhooks/peex/collect'),
                'disbursement' => url('/api/webhooks/peex/disbursement'),
                'remittance' => url('/api/webhooks/peex/remittance'),
            ],
            'accounts' => $accounts,
            'counts' => PeexRequest::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'sandbox_test_numbers' => $client->isSandbox() ? [
                'paid' => ['677000001', '677000002', '677000003'],
                'pending' => ['699000001', '699000002'],
                'failed' => ['677100001', '677100002'],
                'rejected' => ['699100001', '699100002'],
                'note' => 'Numéros de test Cameroun (avec ou sans 237). En sandbox le montant réel est fixé à 10 FCFA.',
            ] : null,
        ]);
    }

    /**
     * Diagnostic PEEX (lecture seule) : chaque service est interrogé (me, get_fees),
     * avec statut HTTP, durée et extrait de réponse ; statistiques des dernières
     * demandes et message prêt à envoyer au support PEEX.
     */
    public function diagnostic(PeexClient $client)
    {
        $probes = [
            'Compte collecte' => $client->probe('collection/me'),
            'Compte décaissement' => $client->probe('disbursement/me'),
            'Compte remittance' => $client->probe('clients/me'),
            'Frais collecte MTN CG (100 XAF)' => $client->probe('collection/get_fees', ['amount' => 100, 'country' => 'CG', 'phone_number' => '242065123456']),
            'Frais collecte Airtel CG (100 XAF)' => $client->probe('collection/get_fees', ['amount' => 100, 'country' => 'CG', 'phone_number' => '242055123456']),
        ];
        $since = now()->subDay();
        $recent = PeexRequest::where('created_at', '>=', $since)->latest()->limit(200)->get();
        $failed5xx = $recent->filter(fn ($r) => preg_match('/PEEX 5\d\d/', (string) $r->message))->values();
        $ip = \Illuminate\Support\Facades\Cache::remember('server:egress_ip', now()->addMinutes(10), function () {
            try {
                return trim(\Illuminate\Support\Facades\Http::timeout(5)->get('https://api.ipify.org')->body()) ?: null;
            } catch (\Throwable) {
                return null;
            }
        });

        $allOk = collect($probes)->every(fn ($p) => $p['ok']);
        $anyUp = collect($probes)->contains(fn ($p) => $p['ok']);
        $verdict = match (true) {
            $allOk && $failed5xx->isEmpty() => 'PEEX répond normalement.',
            $allOk => 'Les comptes PEEX répondent, mais des demandes de paiement ont reçu une erreur 5xx : panne côté PEEX ou côté opérateur (MTN/Airtel) au moment de l\'envoi. À signaler au support PEEX avec les track_id ci-dessous.',
            $anyUp => 'PEEX répond partiellement : certains services sont indisponibles chez PEEX.',
            collect($probes)->contains(fn ($p) => $p['http'] === 403) => 'PEEX refuse l\'accès (403) : l\'adresse IP du serveur doit être autorisée par PEEX.',
            collect($probes)->every(fn ($p) => $p['http'] === 0) => 'PEEX est injoignable depuis le serveur (réseau, DNS ou URL de production).',
            default => 'PEEX est indisponible (erreurs serveur) : panne ou maintenance chez PEEX.',
        };

        $tracks = $failed5xx->take(10)->map(fn ($r) => "- {$r->track_id} · {$r->service} · {$r->phone} · {$r->amount} {$r->currency} · " . $r->created_at?->timezone('Africa/Brazzaville')->format('d/m/Y H:i'))->implode("\n");
        $probeLines = collect($probes)->map(fn ($p, $k) => "- {$k} ({$p['path']}) : HTTP {$p['http']}" . ($p['message'] ? " — {$p['message']}" : ''))->implode("\n");
        $support = "Bonjour l'équipe PEEX,\n\nNous (FlashPay, compte " . ($client->isSandbox() ? 'sandbox' : 'production') . ") recevons des erreurs HTTP 503 « Service Unavailable » sur l'API " . $client->baseUrl()
            . ".\n\nAppels de contrôle depuis notre serveur (" . now()->timezone('Africa/Brazzaville')->format('d/m/Y H:i') . ", heure de Brazzaville) :\n{$probeLines}\n\n"
            . ($tracks ? "Demandes concernées (collection/request_payment) :\n{$tracks}\n\n" : '')
            . 'Adresse IP sortante de notre serveur : ' . ($ip ?: 'inconnue') . "\n\nPouvez-vous nous confirmer l'état de vos services (collecte MTN / Airtel Congo) et si une action est nécessaire de notre côté (activation, IP à autoriser) ?\n\nCordialement,\nFlashPay — Brazzaville";

        return response()->json([
            'base_url' => $client->baseUrl(),
            'sandbox' => $client->isSandbox(),
            'server_ip' => $ip,
            'probes' => $probes,
            'verdict' => $verdict,
            'last24h' => [
                'total' => $recent->count(),
                'errors_5xx' => $failed5xx->count(),
                'unknown' => $recent->where('status', 'unknown')->count(),
                'paid' => $recent->whereIn('status', ['paid', 'success', 'successful'])->count(),
            ],
            'failed_5xx' => $failed5xx->take(20)->map(fn ($r) => $r->only(['id', 'track_id', 'service', 'phone', 'amount', 'currency', 'status', 'message', 'created_at']))->values(),
            'support_message' => $support,
        ]);
    }

    /**
     * Soldes des trois comptes PEEX (tableau de bord Super Admin) :
     *   remittance    GET clients/me       -> solde
     *   disbursement  GET disbursement/me  -> disbursement_solde
     *   collect       GET collection/me    -> collect_solde
     * « engagé » = montants promis (collectes en cours, remboursements) ;
     * « disponible » = solde - engagé. ?refresh=1 force l'appel PEEX (sinon cache 30 s).
     */
    public function balances(Request $request, PeexGuard $guard, PeexClient $client)
    {
        $fresh = $request->boolean('refresh');
        $low = (int) config('flashpay.peex.low_balance_alert', 100000);
        $defs = [
            'remittance' => ['label' => 'Remittance / distribution', 'field' => 'solde', 'payout' => true],
            'disbursement' => ['label' => 'Décaissement', 'field' => 'disbursement_solde', 'payout' => true],
            'collect' => ['label' => 'Collecte', 'field' => 'collect_solde', 'payout' => false],
        ];

        $out = [];
        $total = 0;
        foreach ($defs as $service => $def) {
            try {
                $me = $guard->account($service, $fresh);
                $balance = $me[$def['field']] ?? ($service === 'remittance' ? null : ($me['solde'] ?? null));
                $reserved = $def['payout'] ? $guard->reserved($service) : 0;
                $available = is_numeric($balance) ? (float) $balance - $reserved : null;
                $total += is_numeric($balance) ? (float) $balance : 0;
                $out[$service] = [
                    'ok' => true,
                    'label' => $def['label'],
                    'balance' => is_numeric($balance) ? (float) $balance : null,
                    'reserved' => $reserved,
                    'available' => $available,
                    'activated' => $me['is_activated'] ?? null,
                    'fees' => array_filter(['mtn' => $me['mtn_fees'] ?? null, 'orange' => $me['orange_fees'] ?? null], fn ($v) => $v !== null),
                    'low' => $def['payout'] && $available !== null && $available < $low,
                ];
            } catch (PeexException $e) {
                $out[$service] = ['ok' => false, 'label' => $def['label'], 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'sandbox' => $client->isSandbox(),
            'currency' => 'XAF',
            'total' => $total,
            'low_balance_alert' => $low,
            'accounts' => $out,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function requests(Request $request)
    {
        $q = PeexRequest::with('transaction:id,reference,type,status,stage,failure_reason,source_account,destination_account,amount')
            ->latest();

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }
        if ($request->filled('service')) {
            $q->where('service', $request->string('service'));
        }

        return response()->json($q->paginate(30));
    }

    /**
     * Formulaire « Remboursement PEEX » : retrouve les opérations à rembourser par
     * référence FlashPay (FP-…), track_id PEEX ou numéro de téléphone du client.
     */
    public function refundSearch(Request $request)
    {
        $v = $request->validate(['q' => 'required|string|min:3|max:60']);
        $q = trim($v['q']);
        $digits = preg_replace('/\D/', '', $q);

        $ids = PeexRequest::where('track_id', 'like', '%' . $q . '%')->whereNotNull('transaction_id')->limit(20)->pluck('transaction_id');
        $query = Transaction::with('peexRequests')->where(function ($w) use ($q, $digits, $ids) {
            $w->where('reference', 'like', '%' . strtoupper($q) . '%')->orWhereIn('id', $ids);
            if (strlen($digits) >= 6) {
                $w->orWhere('source_account', 'like', '%' . $digits . '%')->orWhere('destination_account', 'like', '%' . $digits . '%');
            }
        })->latest()->limit(15);

        return response()->json(['data' => $query->get()->map(function (Transaction $t) {
            $p = \App\Support\TransactionPresenter::parties($t);
            return [
                'id' => $t->id,
                'reference' => $t->reference,
                'type' => $t->typeLabel(),
                'status' => $t->status,
                'status_label' => $t->statusLabel(),
                'failure_reason' => $t->failure_reason,
                'amount' => (int) $t->amount,
                'fee' => (int) $t->fee,
                'currency' => $t->currency,
                'created_at' => $t->created_at?->toIso8601String(),
                'sender' => $p['sender_name'] ?? null,
                'sender_phone' => $p['sender_phone'] ?? null,
                'beneficiary' => $p['beneficiary_name'] ?? null,
                'refundable' => \App\Services\Peex\ManualRefundService::refundableOf($t),
                'refunds' => \App\Services\Peex\ManualRefundService::requestsOf($t)->count(),
            ];
        })->values()]);
    }

    /**
     * Lance un test sandbox.
     *  action = collect  : numéro -> wallet admin
     *  action = payout   : wallet admin -> numéro
     *  action = transfer : numéro -> numéro (collecte puis décaissement)
     */
    public function test(Request $request, PeexFlowService $flows)
    {
        $v = $request->validate([
            'action' => 'required|in:collect,payout,transfer',
            'amount' => 'required|integer|min:1',
            'source_phone' => 'required_if:action,collect,transfer|nullable|string',
            'destination_phone' => 'required_if:action,payout,transfer|nullable|string',
            'source_country' => 'nullable|string|size:2',
            'destination_country' => 'nullable|string|size:2',
            'beneficiary_name' => 'nullable|string|max:100',
        ]);

        $meta = array_filter([
            'source_country' => $v['source_country'] ?? null,
            'destination_country' => $v['destination_country'] ?? null,
            'beneficiary_name' => $v['beneficiary_name'] ?? null,
            'description' => 'Test sandbox FlashPay',
            'test' => true,
        ]);

        $user = $request->user();
        $tx = match ($v['action']) {
            'collect' => $flows->cashIn($user, $v['source_phone'], $v['amount'], $meta),
            'payout' => $flows->payout($user, $v['destination_phone'], $v['amount'], $meta),
            'transfer' => $flows->mobileTransfer($user, $v['source_phone'], $v['destination_phone'], $v['amount'], $meta),
        };

        return response()->json($tx->load('peexRequests'));
    }

    public function refresh(PeexRequest $peexRequest, PeexStatusHandler $handler)
    {
        $req = $handler->refresh($peexRequest);

        return response()->json($req->load('transaction'));
    }

    public function sync(PeexStatusHandler $handler)
    {
        $pending = PeexRequest::whereNull('finalized_at')->limit(50)->get();
        $result = $pending->map(fn ($r) => ['track_id' => $r->track_id, 'status' => $handler->refresh($r)->status]);

        return response()->json(['checked' => $result->count(), 'results' => $result]);
    }

    /**
     * Sandbox uniquement : simule le callback PEEX (utile en local, où PEEX
     * ne peut pas joindre http://localhost). N'interroge pas PEEX.
     */
    public function simulateCallback(Request $request, PeexStatusHandler $handler)
    {
        abort_unless(config('flashpay.peex.sandbox'), 403, 'Simulation réservée au mode sandbox');

        $v = $request->validate([
            'track_id' => 'required|string|exists:peex_requests,track_id',
            'status' => 'required|in:paid,failed,rejected,canceled,pending',
        ]);

        $req = $handler->applyCallbackItem([
            'track_id' => $v['track_id'],
            'status' => $v['status'],
            'payment_proof' => $v['status'] === 'paid' ? 'SIMULATED-' . now()->timestamp : 'Simulation ' . $v['status'],
        ]);

        return response()->json($req?->load('transaction'));
    }
}
