<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SwitchService;
use App\Support\TransactionPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function __construct(protected SwitchService $switchService)
    {
    }

    /**
     * Historique des transactions de l'utilisateur connecté.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $walletIds = TransactionPresenter::walletIdsOf($user);

        $q = Transaction::query()
            ->where(fn ($w) => $w->whereIn('source_wallet_id', $walletIds ?: [0])
                ->orWhereIn('destination_wallet_id', $walletIds ?: [0])
                ->orWhere('initiated_by', $user->id));

        $transactions = $q->with(['sourceWallet.user:id,full_name,phone', 'destinationWallet.user:id,full_name,phone', 'initiator:id,full_name,phone'])
            ->latest()
            ->paginate(20);

        $transactions->getCollection()->transform(fn (Transaction $t) => $this->journalLine($t, $walletIds, $user->id));

        return response()->json($transactions);
    }

    public function show(Request $request, Transaction $transaction)
    {
        // Un client ne voit que ses propres opérations.
        abort_unless($transaction->concerns($request->user()), 404);
        $transaction->load('ledgerEntries', 'notes.author', 'initiator', 'sourceWallet.user:id,full_name,phone', 'destinationWallet.user:id,full_name,phone');
        $journal = TransactionPresenter::present($transaction, TransactionPresenter::walletIdsOf($request->user()), $request->user()->id);

        return response()->json($transaction->toArray() + $journal + [
            'type_label' => $journal['label'],
            'status_label' => $transaction->statusLabel(),
        ]);
    }

    /** Ligne du journal : transaction + sens (crédit/débit), libellé, canal, contrepartie. */
    protected function journalLine(Transaction $t, array $walletIds, int $userId): array
    {
        $line = $t->only(['id', 'reference', 'type', 'source_rail', 'destination_rail', 'amount', 'fee', 'currency',
            'status', 'stage', 'failure_reason', 'created_at', 'completed_at', 'destination_amount', 'destination_currency']);
        $line['created_at'] = $t->created_at?->toIso8601String();
        $line['completed_at'] = $t->completed_at?->toIso8601String();

        return $line + TransactionPresenter::present($t, $walletIds, $userId) + ['status_label' => $t->statusLabel()];
    }

    /**
     * Envoi de fonds (compatibilité v1) : wallet -> utilisateur FlashPay ou
     * n'importe quel numéro mobile money couvert (MTN, Airtel, Orange…).
     * Préférer POST /api/pay/transfer (devis, source mobile, pays).
     */
    public function sendMoney(Request $request, \App\Services\Peex\PeexFlowService $flows)
    {
        $validated = $request->validate([
            'destination_phone' => 'required|string',
            'destination_country' => 'nullable|string|size:2',
            'amount' => 'required|integer|min:100',
        ]);

        $transaction = $flows->transfer($request->user(), 'wallet', null, $validated['destination_phone'], $validated['amount'], $validated);

        return response()->json($transaction, $this->httpStatus($transaction));
    }

    /**
     * Paiement marchand (compatibilité v1) depuis le wallet — QR / NFC / saisie.
     */
    public function payMerchant(Request $request, \App\Services\Peex\PeexFlowService $flows)
    {
        $validated = $request->validate([
            'qr_code_token' => 'required_without:merchant_id|string',
            'merchant_id' => 'required_without:qr_code_token|integer',
            'amount' => 'required|integer|min:100',
            'method' => 'required|in:qr,nfc,manual',
        ]);

        $merchant = isset($validated['qr_code_token'])
            ? (Merchant::where('qr_code_token', $validated['qr_code_token'])->first()
                ?? \App\Models\MerchantOutlet::where('qr_code_token', $validated['qr_code_token'])->first()?->merchant)
            : Merchant::find($validated['merchant_id']);
        abort_unless($merchant, 404, 'Marchand introuvable.');

        $transaction = $flows->payMerchant($request->user(), $request->user(), $merchant, 'wallet', null, $validated['amount'], $validated['method']);

        return response()->json($transaction, $this->httpStatus($transaction));
    }

    /** 201 réussi, 202 en attente de confirmation d'un rail asynchrone (PEEX), 422 échec. */
    protected function httpStatus(Transaction $transaction): int
    {
        return match ($transaction->status) {
            'successful' => 201,
            'processing' => 202,
            default => 422,
        };
    }
}
