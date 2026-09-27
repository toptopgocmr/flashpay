<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * FlashPay Ledger — grand livre comptable.
 * Chaque mouvement de fonds génère AU MOINS deux écritures (débit / crédit)
 * afin de garantir l'équilibre comptable et la traçabilité totale,
 * conformément au §6 (Sécurité / audit) du cahier des charges.
 */
class LedgerService
{
    /**
     * Enregistre une écriture de débit + crédit liées à une transaction.
     */
    public function recordDoubleEntry(Transaction $transaction, string $debitAccount, string $creditAccount, int $amountMinor, ?string $currency = null, ?string $memo = null): void
    {
        if ($amountMinor <= 0) {
            return;
        }
        $currency ??= $transaction->currency;

        DB::transaction(function () use ($transaction, $debitAccount, $creditAccount, $amountMinor, $currency, $memo) {
            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'account' => $debitAccount,
                'type' => 'debit',
                'amount' => $amountMinor,
                'currency' => $currency,
                'balance_after' => null, // calculé si compte interne (wallet)
                'memo' => ($memo ? "{$memo} — " : '') . "Débit pour transaction #{$transaction->reference}",
            ]);

            LedgerEntry::create([
                'transaction_id' => $transaction->id,
                'account' => $creditAccount,
                'type' => 'credit',
                'amount' => $amountMinor,
                'currency' => $currency,
                'balance_after' => null,
                'memo' => ($memo ? "{$memo} — " : '') . "Crédit pour transaction #{$transaction->reference}",
            ]);
        });
    }

    public function entriesFor(Transaction $transaction)
    {
        return LedgerEntry::where('transaction_id', $transaction->id)->orderBy('id')->get();
    }
}
