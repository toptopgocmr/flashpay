<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SettlementAccount;
use App\Models\Transaction;
use App\Services\SettlementService;
use Illuminate\Http\Request;

/**
 * Règlements marchand (app) et file des virements bancaires (console).
 *
 * Marchand
 *   GET    /api/merchant/settlement                         solde, comptes, réglage auto, historique
 *   POST   /api/merchant/settlement/accounts                ajouter un compte
 *   POST   /api/merchant/settlement/accounts/{id}/default   compte par défaut
 *   DELETE /api/merchant/settlement/accounts/{id}
 *   POST   /api/merchant/settlement/settle                  {account_id, amount}
 *   POST   /api/merchant/settlement/auto                    {mode: none|daily|weekly, min}
 * Admin
 *   GET    /api/admin/settlements/bank                      virements à exécuter / récents
 *   POST   /api/admin/settlements/{transaction}/complete    {bank_ref}
 *   POST   /api/admin/settlements/{transaction}/reject      {reason}
 */
class SettlementController extends Controller
{
    public function __construct(protected SettlementService $settlements)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $m = $user->merchant ?? abort(403, 'Compte marchand requis.');

        return response()->json([
            'balance' => (int) ($user->wallet?->balance ?? 0),
            'currency' => $user->wallet?->currency ?? 'XAF',
            'country' => $m->country,
            'types' => SettlementAccount::TYPES,
            'accounts' => $this->settlements->accounts($m)->map->toApi(),
            'auto_settlement' => $m->auto_settlement ?? 'none',
            'auto_settlement_min' => (int) ($m->auto_settlement_min ?? 0),
            'last_auto_settlement_at' => $m->last_auto_settlement_at,
            'history' => $this->settlements->history($user),
        ]);
    }

    public function addAccount(Request $request)
    {
        $m = $request->user()->merchant ?? abort(403);
        $v = $request->validate([
            'type' => 'required|in:mobile_money,bank,wallet,cash_pickup',
            'label' => 'nullable|string|max:80',
            'country' => 'nullable|string|size:2',
            'phone' => 'nullable|string|max:25',
            'bank_name' => 'nullable|string|max:120',
            'account_holder' => 'nullable|string|max:120',
            'account_number' => 'nullable|string|max:60',
            'swift' => 'nullable|string|max:20',
            'is_default' => 'nullable|boolean',
        ]);

        return response()->json($this->settlements->addAccount($m, $v)->toApi(), 201);
    }

    public function setDefault(Request $request, SettlementAccount $account)
    {
        abort_unless($account->merchant_id === $request->user()->merchant?->id, 404);
        $this->settlements->setDefault($account);
        return response()->json(['ok' => true]);
    }

    public function deleteAccount(Request $request, SettlementAccount $account)
    {
        abort_unless($account->merchant_id === $request->user()->merchant?->id, 404);
        $wasDefault = $account->is_default;
        $mid = $account->merchant_id;
        $account->delete();
        if ($wasDefault && ($next = SettlementAccount::where('merchant_id', $mid)->first())) {
            $this->settlements->setDefault($next);
        }
        return response()->json(['ok' => true]);
    }

    public function settle(Request $request)
    {
        $v = $request->validate(['account_id' => 'required|integer', 'amount' => 'required|integer|min:100']);
        $acc = SettlementAccount::findOrFail($v['account_id']);
        $r = $this->settlements->settle($request->user(), $acc, $v['amount']);
        $tx = $r['transaction'];

        return response()->json(app(PaymentController::class)->txPayload($tx) + ['code' => $r['code'] ?? null, 'account' => $acc->toApi()], match ($tx->status) {
            'successful' => 201,
            'processing' => 202,
            default => 422,
        });
    }

    public function auto(Request $request)
    {
        $m = $request->user()->merchant ?? abort(403);
        $v = $request->validate(['mode' => 'required|in:none,daily,weekly', 'min' => 'nullable|integer|min:0']);
        if ($v['mode'] !== 'none' && ! $m->settlementAccounts()->where('is_default', true)->exists()) {
            return response()->json(['message' => 'Choisissez d\'abord un compte de règlement par défaut.'], 422);
        }
        $m->update(['auto_settlement' => $v['mode'], 'auto_settlement_min' => $v['min'] ?? 0]);
        return response()->json(['auto_settlement' => $m->auto_settlement, 'auto_settlement_min' => (int) $m->auto_settlement_min]);
    }

    // ------------------------------------------------------------ Admin

    public function bankQueue()
    {
        $pending = Transaction::where('type', 'bank_transfer')->where('status', 'processing')->oldest()->get();
        $recent = Transaction::where('type', 'bank_transfer')->where('status', '<>', 'processing')->latest()->limit(30)->get();
        $map = fn ($t) => [
            'id' => $t->id,
            'reference' => $t->reference,
            'merchant' => $t->meta['merchant_name'] ?? null,
            'bank_name' => $t->meta['bank_name'] ?? null,
            'account_holder' => $t->meta['account_holder'] ?? null,
            'account_number' => $t->meta['account_number'] ?? $t->destination_account,
            'swift' => $t->meta['swift'] ?? null,
            'amount' => $t->amount,
            'fee' => $t->fee,
            'currency' => $t->currency,
            'status' => $t->status,
            'bank_ref' => $t->destination_external_ref,
            'failure_reason' => $t->failure_reason,
            'auto' => ($t->meta['settlement'] ?? null) === 'auto',
            'created_at' => $t->created_at,
            'completed_at' => $t->completed_at,
        ];

        return response()->json([
            'pending' => $pending->map($map)->values(),
            'recent' => $recent->map($map)->values(),
            'pending_total' => (int) $pending->sum('amount'),
        ]);
    }

    public function completeBank(Request $request, Transaction $transaction)
    {
        $v = $request->validate(['bank_ref' => 'required|string|max:64']);
        return response()->json($this->settlements->completeBank($transaction, $v['bank_ref']));
    }

    public function rejectBank(Request $request, Transaction $transaction)
    {
        $v = $request->validate(['reason' => 'required|string|max:200']);
        return response()->json($this->settlements->rejectBank($transaction, $v['reason']));
    }
}
