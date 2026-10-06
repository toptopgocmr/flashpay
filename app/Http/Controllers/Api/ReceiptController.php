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

    /** Console admin : lien du reçu de n'importe quelle transaction. */
    public function adminLink(Transaction $transaction)
    {
        return response()->json([
            'url' => URL::temporarySignedRoute('receipt.show', now()->addDays(7), ['transaction' => $transaction->reference]),
            'reference' => $transaction->reference,
        ]);
    }

    /**
     * Console admin : plusieurs reçus sur une seule page imprimable
     * (un reçu par page A4, ou à la suite en format ticket).
     *   POST /api/admin/transactions/receipts {ids: [..]}  (100 max)
     */
    public function adminBatchLink(Request $request)
    {
        $v = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $refs = Transaction::whereIn('id', $v['ids'])->orderByDesc('id')->pluck('reference')->all();
        abort_if(! $refs, 404);

        return response()->json([
            'url' => URL::temporarySignedRoute('receipt.batch', now()->addHours(2), ['refs' => implode(',', $refs), 'autoprint' => 1]),
            'count' => count($refs),
        ]);
    }

    public function batch(Request $request)
    {
        $refs = array_slice(array_filter(explode(',', (string) $request->query('refs'))), 0, 100);
        $txs = Transaction::with(['sourceWallet.user', 'destinationWallet.user', 'peexRequests'])
            ->whereIn('reference', $refs)->orderByDesc('id')->get();

        return response()->view('receipts', ['receipts' => $txs->map(fn ($t) => self::data($t))->all()]);
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
        foreach ($tx->peexRequests as $pr) {
            $j = json_decode((string) $pr->payment_proof, true);
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
            // Dépôt / retrait en espèces : agent qui a servi le client
            'agent' => match ($tx->type) {
                'cash_in' => self::agentLabel($src, $m['agent_name'] ?? null),
                'cash_out', 'cash_pickup' => self::agentLabel($dst, $m['agent_name'] ?? null),
                default => null,
            },
        ];
    }

    protected static function agentLabel($user, ?string $fallback): ?string
    {
        $agent = $user?->agent;
        if (! $agent) {
            return $fallback;
        }
        $code = $agent->agent_code ? ' (' . $agent->agent_code . ')' : '';

        return trim(($user->full_name ?: $fallback) . $code);
    }
}
