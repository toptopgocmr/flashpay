<?php

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * FlashPay Wallet — gestion des comptes et soldes internes.
 * Toutes les opérations passent par des transactions DB verrouillées
 * (lockForUpdate) pour éviter les problèmes de concurrence sur le solde.
 */
class WalletService
{
    public function credit(Wallet $wallet, int $amountMinor): Wallet
    {
        return DB::transaction(function () use ($wallet, $amountMinor) {
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();
            $locked->balance += $amountMinor;
            $locked->save();
            return $locked;
        });
    }

    public function debit(Wallet $wallet, int $amountMinor): Wallet
    {
        return DB::transaction(function () use ($wallet, $amountMinor) {
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->first();

            if ($locked->status === 'frozen') {
                throw new InsufficientFundsException('Wallet gelé par FlashPay : opération impossible. Contactez le support.');
            }
            if ($locked->balance < $amountMinor) {
                throw new InsufficientFundsException('Solde insuffisant pour effectuer cette opération.');
            }

            $locked->balance -= $amountMinor;
            $locked->save();
            return $locked;
        });
    }

    public function transferInternal(Wallet $from, Wallet $to, int $amountMinor): void
    {
        DB::transaction(function () use ($from, $to, $amountMinor) {
            $this->debit($from, $amountMinor);
            $this->credit($to, $amountMinor);
        });
    }
}
