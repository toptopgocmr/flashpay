<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Services\Peex\ManualRefundService;
use App\Services\Peex\PeexStatusHandler;
use App\Support\TransactionPresenter;
use Illuminate\Http\Request;

/** Console : remboursements manuels PEEX (mobile money / compte bancaire). */
class AdminRefundController extends Controller
{
    public function __construct(protected ManualRefundService $refunds)
    {
    }

    public function index(Transaction $transaction)
    {
        $transaction->load('peexRequests');
        $p = TransactionPresenter::parties($transaction);
        $meta = $transaction->meta ?? [];
        $mobileRail = in_array($transaction->source_rail, ['peex', 'digitwace'], true);

        return response()->json([
            'transaction' => [
                'id' => $transaction->id, 'reference' => $transaction->reference, 'status' => $transaction->status,
                'amount' => (int) $transaction->amount, 'fee' => (int) $transaction->fee, 'currency' => $transaction->currency,
            ],
            'refundable' => ManualRefundService::refundableOf($transaction),
            // Valeurs proposées : le payeur d'origine
            'defaults' => [
                'channel' => 'mobile',
                'phone' => $mobileRail ? TransactionPresenter::phone($transaction->source_account) : ($p['sender_phone']),
                'beneficiary_name' => $meta['payer_verified_name'] ?? $p['sender_name'],
                'country' => $meta['source_country'] ?? 'CG',
                'currency' => $transaction->currency,
            ],
            'refunds' => ManualRefundService::requestsOf($transaction)->map(fn ($r) => $this->refunds->present($r))->values(),
        ]);
    }

    public function store(Request $request, Transaction $transaction)
    {
        $v = $request->validate([
            'channel' => 'required|in:mobile,bank',
            'amount' => 'required|integer|min:1',
            'currency' => 'nullable|string|size:3',
            'reason' => 'required|string|max:190',
            'beneficiary_name' => 'required|string|max:120',
            // Mobile money
            'phone' => 'required_if:channel,mobile|nullable|string|max:20',
            'country' => 'nullable|string|size:2',
            // Compte bancaire (PEEX › Bank Payment Request)
            'bank_name' => 'nullable|string|max:120',
            'bank_address' => 'required_if:channel,bank|nullable|string|max:190',
            'bank_iban' => 'required_if:channel,bank|nullable|string|max:40',
            'bank_swift' => 'required_if:channel,bank|nullable|string|min:6|max:11',
            'to_country' => 'required_if:channel,bank|nullable|string|size:2',
            'to_currency' => 'nullable|string|size:3',
            'mobile_phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:120',
            'purpose' => 'nullable|string|max:120',
            'fund_origin' => 'nullable|string|max:120',
        ], [
            'bank_iban.required_if' => 'IBAN / RIB obligatoire pour un remboursement bancaire.',
            'bank_swift.required_if' => 'Code SWIFT / BIC obligatoire pour un remboursement bancaire.',
            'bank_address.required_if' => 'Adresse de la banque obligatoire.',
            'to_country.required_if' => 'Pays de la banque obligatoire.',
            'phone.required_if' => 'Numéro mobile money obligatoire.',
        ]);

        $res = $this->refunds->refund($transaction, $v, $request->user());
        $msg = match ($res['status']) {
            'failed' => 'Remboursement refusé par PEEX : ' . ($res['error'] ?? 'erreur'),
            'successful' => "Remboursement effectué ({$res['track_id']}).",
            default => "Remboursement envoyé à PEEX ({$res['track_id']}) : en cours de traitement.",
        };

        return response()->json(['message' => $msg] + $res, $res['status'] === 'failed' ? 422 : 201);
    }

    /** Relance la vérification du statut auprès de PEEX. */
    public function refresh(Transaction $transaction, PeexRequest $peexRequest, PeexStatusHandler $handler)
    {
        abort_unless($peexRequest->transaction_id === $transaction->id, 404);
        $handler->refresh($peexRequest);

        return response()->json(['request' => $this->refunds->present($peexRequest->fresh())]);
    }
}
