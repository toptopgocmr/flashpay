<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SwitchService;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function __construct(protected SwitchService $switchService)
    {
    }

    /** Accueil de l'app Agent : float, activité du jour, commissions. */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $wallet = $user->wallet;
        $wid = $wallet?->id;
        $today = now()->startOfDay();

        $cashIn = \App\Models\Transaction::where('source_wallet_id', $wid)->where('type', 'cash_in')->where('status', 'successful');
        $cashOut = \App\Models\Transaction::where('destination_wallet_id', $wid)->whereIn('type', ['cash_pickup', 'cash_out'])->where('status', 'successful');

        $commission = (int) \App\Models\LedgerEntry::where('account', "wallet:{$wid}")
            ->where('type', 'credit')->where('memo', 'like', 'Commission agent%')
            ->where('created_at', '>=', now()->startOfMonth())->sum('amount');
        $commissionToday = (int) \App\Models\LedgerEntry::where('account', "wallet:{$wid}")
            ->where('type', 'credit')->where('memo', 'like', 'Commission agent%')
            ->where('created_at', '>=', $today)->sum('amount');

        return response()->json([
            'agent' => $user->agent,
            'name' => $user->full_name,
            'float' => (int) ($wallet?->balance ?? 0),
            'currency' => $wallet?->currency ?? 'XAF',
            'today' => [
                'cash_in_count' => (clone $cashIn)->where('created_at', '>=', $today)->count(),
                'cash_in_volume' => (int) (clone $cashIn)->where('created_at', '>=', $today)->sum('amount'),
                'cash_out_count' => (clone $cashOut)->where('created_at', '>=', $today)->count(),
                'cash_out_volume' => (int) (clone $cashOut)->where('created_at', '>=', $today)->sum('amount'),
            ],
            'commission_month' => $commission,
            'commission_today' => $commissionToday,
            'pending_operations' => \App\Models\Transaction::where(fn ($q) => $q->where('source_wallet_id', $wid)->orWhere('destination_wallet_id', $wid))->where('status', 'processing')->count(),
            'pending_float_requests' => \App\Models\FloatRequest::where('agent_id', $user->agent?->id)->where('status', 'pending')->count(),
            'low_float' => $wallet && $user->agent && $wallet->balance < $user->agent->low_float_threshold,
        ]);
    }

    /**
     * Cash-out direct (sans bon de retrait) : le client consent par un code OTP
     * reçu par SMS (§3.1.4). Préférer le bon de retrait (code/QR généré par le client).
     */
    public function cashOut(Request $request, \App\Services\Security\OtpService $otp)
    {
        $validated = $request->validate([
            'client_phone' => 'required|string|max:25',
            'amount' => 'required|integer|min:500',
            'otp' => 'nullable|string|max:10',
        ]);

        $client = User::whereIn('phone', \App\Support\Phone::candidates($validated['client_phone']))->first();
        abort_unless($client && $client->wallet, 422, 'Aucun compte FlashPay pour ce numéro.');
        if (empty($validated['otp'])) {
            $sent = $otp->send($client->phone, 'sensitive', ['agent_user_id' => $request->user()->id, 'amount' => $validated['amount']],
                'FlashPay: retrait de ' . number_format($validated['amount'], 0, ',', ' ') . ' chez l\'agent ' . $request->user()->full_name . '. Code: {code}. Ne le donnez que si vous recevez les especes.');
            return response()->json(['confirmation_required' => true, 'message' => 'Demandez au client le code reçu par SMS.'] + $sent, 202);
        }
        $ctx = $otp->verify($client->phone, 'sensitive', $validated['otp']);
        abort_unless(($ctx['agent_user_id'] ?? null) === $request->user()->id && (int) ($ctx['amount'] ?? 0) === (int) $validated['amount'], 422, 'Le code ne correspond pas à ce retrait.');

        $transaction = $this->switchService->process([
            'type' => 'cash_out',
            'scope' => 'national',
            'source_rail' => 'wallet',
            'source_wallet_id' => $client->wallet->id,
            'destination_rail' => 'wallet',
            'destination_wallet_id' => $request->user()->wallet->id,
            'amount' => $validated['amount'],
            'currency' => $client->wallet->currency,
            'initiated_by' => $request->user()->id,
            'meta' => ['channel' => 'agent', 'client_name' => $client->full_name, 'agent_name' => $request->user()->full_name],
        ]);

        return response()->json($transaction, $transaction->status === 'successful' ? 201 : 422);
    }

    // ------------------------------------------------ Approvisionnement (§3.1.2)

    public function floatRequests(Request $request)
    {
        $agent = $request->user()->agent;
        return response()->json([
            'mine' => \App\Models\FloatRequest::where('agent_id', $agent->id)->latest()->limit(50)->get(),
            // super-agent : demandes de ses sous-agents à valider
            'to_review' => $agent->is_super_agent
                ? \App\Models\FloatRequest::with('agent.user:id,full_name,phone')->where('super_agent_id', $agent->id)->where('status', 'pending')->latest()->get()
                : [],
            'sub_agents' => $agent->is_super_agent ? $agent->subAgents()->with('user:id,full_name,phone', 'user.wallet:id,user_id,balance,currency')->get() : [],
        ]);
    }

    public function createFloatRequest(Request $request, \App\Services\Agent\FloatRequestService $service)
    {
        $v = $request->validate([
            'amount' => 'required|integer|min:1000',
            'method' => 'required|in:cash_deposit,bank_transfer,super_agent',
            'proof_reference' => 'nullable|string|max:120',
            'super_agent_code' => 'nullable|string|max:20',
            'note' => 'nullable|string|max:200',
        ]);
        return response()->json($service->create($request->user(), $v), 201);
    }

    public function cancelFloatRequest(Request $request, \App\Models\FloatRequest $floatRequest)
    {
        abort_unless($floatRequest->agent_id === $request->user()->agent->id && $floatRequest->status === 'pending', 422, 'Demande non annulable.');
        $floatRequest->update(['status' => 'cancelled']);
        return response()->json($floatRequest);
    }

    public function reviewFloatRequest(Request $request, \App\Models\FloatRequest $floatRequest, \App\Services\Agent\FloatRequestService $service)
    {
        $v = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required_if:decision,reject|nullable|string|max:200']);
        $r = $v['decision'] === 'approve'
            ? $service->approve($floatRequest, $request->user())
            : $service->reject($floatRequest, $request->user(), $v['reason']);
        return response()->json($r);
    }

    // ------------------------------------------------ Caisse (§3.1.6, §4.6.5)

    public function float(Request $request, \App\Services\Agent\AgentLedgerService $ledger)
    {
        return response()->json($ledger->breakdown($request->user()->wallet));
    }

    public function journal(Request $request, \App\Services\Agent\AgentLedgerService $ledger)
    {
        $v = $request->validate(['type' => 'nullable|string|max:30', 'limit' => 'nullable|integer|min:1|max:200']);
        return response()->json(['data' => $ledger->journal($request->user()->wallet, $v['limit'] ?? 50, $v['type'] ?? null)]);
    }

    public function reconciliation(Request $request, \App\Services\Agent\AgentLedgerService $ledger)
    {
        $v = $request->validate(['date' => 'nullable|date']);
        return response()->json($ledger->reconciliation($request->user()->wallet, \Illuminate\Support\Carbon::parse($v['date'] ?? 'today')));
    }

    public function commissions(Request $request)
    {
        return response()->json([
            'rules' => \App\Models\CommissionRule::where('active', true)->orderBy('operation')->orderBy('min_amount')->get(),
            'labels' => \App\Services\Agent\CommissionService::OPERATIONS,
        ]);
    }

    /**
     * Historique agent (dépôts / retraits) : filtres type, période, recherche,
     * totaux de la sélection, et détail complet de chaque opération (reçu).
     *   GET /api/agent/history?kind=deposit|withdrawal&from=Y-m-d&to=Y-m-d&q=…&status=…
     */
    public function history(Request $request)
    {
        $walletId = $request->user()->wallet?->id;
        $v = $request->validate([
            'kind' => 'nullable|in:deposit,withdrawal',
            'status' => 'nullable|in:successful,processing,failed,reversed',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'q' => 'nullable|string|max:60',
        ]);

        $q = \App\Models\Transaction::where(fn ($q) => $q->where('source_wallet_id', $walletId)->orWhere('destination_wallet_id', $walletId))
            ->whereIn('type', match ($v['kind'] ?? null) {
                'deposit' => ['cash_in'],
                'withdrawal' => ['cash_out', 'cash_pickup'],
                default => ['cash_in', 'cash_out', 'cash_pickup'],
            });
        if (! empty($v['status'])) {
            $q->where('status', $v['status']);
        }
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($v['from'], 'Africa/Brazzaville')->startOfDay()->utc());
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($v['to'], 'Africa/Brazzaville')->endOfDay()->utc());
        }
        if ($term = trim((string) ($v['q'] ?? ''))) {
            $digits = preg_replace('/\D/', '', $term);
            $q->where(function ($w) use ($term, $digits) {
                $w->where('reference', 'like', "%{$term}%")->orWhere('meta', 'like', "%{$term}%");
                if (strlen($digits) >= 6) {
                    $w->orWhere('source_account', 'like', "%{$digits}%")->orWhere('destination_account', 'like', "%{$digits}%")
                        ->orWhereHas('sourceWallet.user', fn ($u) => $u->where('phone', 'like', "%{$digits}%"))
                        ->orWhereHas('destinationWallet.user', fn ($u) => $u->where('phone', 'like', "%{$digits}%"));
                }
            });
        }

        // Totaux de la sélection (opérations réussies)
        $ok = (clone $q)->where('status', 'successful')->get(['type', 'amount', 'meta']);
        $summary = [
            'deposits' => ['count' => $ok->where('type', 'cash_in')->count(), 'amount' => (int) $ok->where('type', 'cash_in')->sum('amount')],
            'withdrawals' => ['count' => $ok->where('type', '!=', 'cash_in')->count(), 'amount' => (int) $ok->where('type', '!=', 'cash_in')->sum('amount')],
            'commission' => (int) $ok->sum(fn ($t) => (int) ($t->meta['agent_commission'] ?? 0)),
            'count' => (clone $q)->count(),
        ];

        $ops = $q->with(['sourceWallet.user:id,full_name,phone', 'destinationWallet.user:id,full_name,phone'])->latest()->paginate(20);

        $ops->getCollection()->transform(function ($t) {
            $p = \App\Support\TransactionPresenter::parties($t);
            $deposit = $t->type === 'cash_in';

            return [
                'id' => $t->id,
                'reference' => $t->reference,
                'type' => $t->type,
                'kind' => $deposit ? 'deposit' : 'withdrawal',
                'label' => $deposit ? 'Dépôt' : 'Retrait cash',
                'client' => $t->meta['client_name'] ?? $t->meta['beneficiary_name'] ?? ($deposit ? $p['beneficiary_name'] : $p['sender_name']),
                'client_phone' => $deposit ? $p['beneficiary_phone'] : $p['sender_phone'],
                'amount' => $t->amount,
                'fee' => (int) $t->fee,
                'currency' => $t->currency,
                'commission' => (int) ($t->meta['agent_commission'] ?? 0),
                'status' => $t->status,
                'status_label' => $t->statusLabel(),
                'failure_reason' => $t->failure_reason,
                'created_at' => $t->created_at,
                'completed_at' => $t->completed_at,
                // Récapitulatif (même format que le reçu client : FpTxRecap)
                'details' => \App\Support\TransactionPresenter::details($t, null, $deposit ? 'Dépôt d\'espèces' : 'Retrait d\'espèces'),
            ] + $p;
        });

        return response()->json($ops->toArray() + ['summary' => $summary]);
    }
}
