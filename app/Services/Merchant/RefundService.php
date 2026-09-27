<?php

namespace App\Services\Merchant;

use App\Exceptions\BusinessException;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\Notifications\TransactionNotifier;
use App\Services\WalletService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remboursement total ou partiel d'un paiement marchand, tous canaux (§13.2, §4.7.2) :
 * initié par le marchand (délai max configurable), l'administrateur (arbitrage
 * de litige) ou l'API e-commerce. Débite le wallet marchand, crédite le payeur.
 * Paiement d'origine par mobile money : crédit sur le wallet FlashPay du payeur s'il existe.
 */
class RefundService
{
    public function __construct(protected WalletService $wallets, protected LedgerService $ledger)
    {
    }

    public function refund(Transaction $original, int $amount, string $role, ?User $by, ?string $reason = null, ?int $intentId = null): Refund
    {
        return DB::transaction(function () use ($original, $amount, $role, $by, $reason, $intentId) {
            $tx = Transaction::whereKey($original->id)->lockForUpdate()->first();
            if (! in_array($tx->type, TransactionNotifier::MERCHANT_TYPES, true) || $tx->status !== 'successful') {
                throw new BusinessException('Seul un paiement marchand réussi peut être remboursé.', 'not_refundable');
            }
            if ($role === 'merchant' && $tx->created_at->lt(now()->subDays(config('security.refund_window_days', 30)))) {
                throw new BusinessException('Délai de remboursement dépassé (' . config('security.refund_window_days', 30) . ' jours). Contactez le support.', 'refund_window');
            }
            $already = (int) Refund::where('transaction_id', $tx->id)->where('status', 'completed')->sum('amount');
            if ($amount <= 0 || $already + $amount > $tx->amount) {
                throw new BusinessException('Montant de remboursement supérieur au montant restant (' . ($tx->amount - $already) . ').', 'refund_amount');
            }

            $merchantWallet = Wallet::findOrFail($tx->destination_wallet_id);
            $payerWallet = $tx->source_wallet_id ? Wallet::find($tx->source_wallet_id) : $this->walletByPhone($tx->source_account);
            if (! $payerWallet) {
                throw new BusinessException('Le payeur n\'a pas de wallet FlashPay : remboursement à traiter par le support.', 'no_payer_wallet');
            }
            $merchantId = $tx->meta['merchant_id'] ?? null;

            // Le marchand rembourse la part nette reçue au prorata ; la commission correspondante est rendue par FlashPay.
            $feeBack = $tx->merchant_fee > 0 ? (int) floor($tx->merchant_fee * $amount / $tx->amount) : 0;
            $fromMerchant = $amount - $feeBack;

            $rtx = Transaction::create([
                'reference' => 'FP-' . Str::upper(Str::random(12)),
                'type' => 'refund',
                'scope' => $tx->scope,
                'source_rail' => 'wallet',
                'destination_rail' => 'wallet',
                'source_wallet_id' => $merchantWallet->id,
                'destination_wallet_id' => $payerWallet->id,
                'amount' => $amount,
                'currency' => $tx->currency,
                'status' => 'processing',
                'initiated_by' => $by?->id ?? $merchantWallet->user_id,
                'meta' => array_filter(['original_transaction_id' => $tx->id, 'original_reference' => $tx->reference, 'merchant_id' => $merchantId, 'merchant_name' => $tx->meta['merchant_name'] ?? null, 'reason' => $reason, 'initiated_by_role' => $role]),
            ]);

            $this->wallets->debit($merchantWallet, $fromMerchant);
            $this->ledger->recordDoubleEntry($rtx, "wallet:{$merchantWallet->id}", "wallet:{$payerWallet->id}", $fromMerchant, null, 'Remboursement marchand');
            if ($feeBack > 0) {
                $this->ledger->recordDoubleEntry($rtx, 'flashpay:fees', "wallet:{$payerWallet->id}", $feeBack, null, 'Remboursement commission marchand');
            }
            $this->wallets->credit($payerWallet, $amount);
            $rtx->update(['status' => 'successful', 'completed_at' => now()]);

            $refund = Refund::create([
                'public_id' => 're_' . Str::lower(Str::random(20)),
                'transaction_id' => $tx->id,
                'payment_intent_id' => $intentId,
                'merchant_id' => $merchantId ?? \App\Models\Merchant::where('user_id', $merchantWallet->user_id)->value('id'),
                'amount' => $amount,
                'currency' => $tx->currency,
                'reason' => $reason,
                'initiated_by_role' => $role,
                'initiated_by' => $by?->id,
                'refund_transaction_id' => $rtx->id,
                'status' => 'completed',
            ]);

            Audit::log('refund.create', $refund, ['original' => $tx->reference, 'amount' => $amount, 'role' => $role], $by?->id);
            app(TransactionNotifier::class)->handle($rtx->fresh());

            return $refund;
        });
    }

    protected function walletByPhone(?string $phone): ?Wallet
    {
        if (! $phone) {
            return null;
        }
        $digits = ltrim($phone, '+');
        return User::whereIn('phone', [$digits, '+' . $digits])->first()?->wallet;
    }
}
