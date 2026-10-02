<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Reçu de transaction : l'application demande un lien signé (valable 7 jours),
 * qu'elle ouvre ou partage ; la page s'imprime / s'enregistre en PDF.
 */
class ReceiptController extends Controller
{
    public function link(Request $request, Transaction $transaction)
    {
        abort_unless($transaction->concerns($request->user()), 404);

        return response()->json([
            'url' => URL::temporarySignedRoute('receipt.show', now()->addDays(7), ['transaction' => $transaction->reference]),
            'reference' => $transaction->reference,
        ]);
    }

    public function show(string $transaction)
    {
        $tx = Transaction::with(['sourceWallet.user', 'destinationWallet.user', 'peexRequests'])
            ->where('reference', $transaction)->firstOrFail();

        return response()->view('receipt', ['tx' => $tx, 'r' => self::data($tx)]);
    }

    /** Données du reçu (aussi utilisées par les tests). */
    public static function data(Transaction $tx): array
    {
        $m = $tx->meta ?? [];
        $src = $tx->sourceWallet?->user;
        $dst = $tx->destinationWallet?->user;
        $operatorRef = null;
        $p = \App\Support\TransactionPresenter::parties($tx);
        foreach ($tx->peexRequests as $p) {
            $j = json_decode((string) $p->payment_proof, true);
            $operatorRef = $operatorRef ?: ($j['financialTransactionId'] ?? null);
        }

        return [
            'reference' => $tx->reference,
            'type' => $tx->typeLabel(),
            'status' => $tx->statusLabel(),
            'status_code' => $tx->status,
            'date' => $tx->created_at?->timezone('Africa/Brazzaville')->format('d/m/Y à H:i'),
            'completed' => $tx->completed_at?->timezone('Africa/Brazzaville')->format('d/m/Y à H:i'),
            'amount' => (int) $tx->amount,
            'fee' => (int) $tx->fee,
            'total' => (int) $tx->amount + (int) $tx->fee,
            'currency' => $tx->currency ?: 'XAF',
            'received' => (int) ($tx->destination_amount ?: $tx->amount),
            'received_currency' => $tx->destination_currency ?: ($tx->currency ?: 'XAF'),
            'sender' => $src?->full_name ?? ($m['sender_name'] ?? $m['payer_name'] ?? $p['sender_name']),
            'sender_account' => $tx->source_account ?: ($src?->phone ?: $p['sender_phone']),
            'beneficiary' => $m['merchant_name'] ?? $dst?->full_name ?? ($m['beneficiary_name'] ?? $p['beneficiary_name']),
            'beneficiary_account' => $tx->destination_account ?: ($dst?->phone ?: $p['beneficiary_phone']),
            'note' => $m['note'] ?? null,
            'operator_ref' => $operatorRef,
            'failure' => $tx->status === 'failed' ? $tx->failure_reason : null,
        ];
    }
}
