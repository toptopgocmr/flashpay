<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SwitchService;
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
        $walletId = $request->user()->wallet?->id;

        $transactions = Transaction::where('source_wallet_id', $walletId)
            ->orWhere('destination_wallet_id', $walletId)
            ->orWhere('initiated_by', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($transactions);
    }

    public function show(Request $request, Transaction $transaction)
    {
        $transaction->load('ledgerEntries', 'notes.author', 'initiator');
        return response()->json($transaction);
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
