<?php

namespace App\Services\Ops;

use App\Exceptions\BusinessException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\Notifications\NotificationService;
use App\Services\WalletService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Intervention exceptionnelle du super admin (§3.4.3) : crédit ou débit direct
 * d'un wallet agent, marchand ou client, avec motif obligatoire, écriture au
 * ledger (compte flashpay:adjustments), journal d'audit et notification (§11.4).
 */
class WalletAdjustmentService
{
    public const ACCOUNT = 'flashpay:adjustments';

    public function __construct(protected WalletService $wallets, protected LedgerService $ledger, protected NotificationService $notify)
    {
    }

    public function adjust(Wallet $wallet, string $direction, int $amount, string $reason, User $admin): Transaction
    {
        if ($amount <= 0) {
            throw new BusinessException('Montant invalide.');
        }
        return DB::transaction(function () use ($wallet, $direction, $amount, $reason, $admin) {
            $credit = $direction === 'credit';
            $tx = Transaction::create([
                'reference' => 'FP-' . Str::upper(Str::random(12)),
                'type' => 'adjustment', 'scope' => 'national',
                'source_rail' => $credit ? 'treasury' : 'wallet',
                'destination_rail' => $credit ? 'wallet' : 'treasury',
                'source_wallet_id' => $credit ? null : $wallet->id,
                'destination_wallet_id' => $credit ? $wallet->id : null,
                'amount' => $amount, 'currency' => $wallet->currency,
                'status' => 'processing', 'initiated_by' => $admin->id,
                'meta' => ['channel' => 'adjustment', 'direction' => $direction, 'reason' => $reason, 'admin' => $admin->full_name],
            ]);
            if ($credit) {
                $this->wallets->credit($wallet, $amount);
                $this->ledger->recordDoubleEntry($tx, self::ACCOUNT, "wallet:{$wallet->id}", $amount, null, 'Ajustement crédit : ' . $reason);
            } else {
                $this->wallets->debit($wallet, $amount);
                $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", self::ACCOUNT, $amount, null, 'Ajustement débit : ' . $reason);
            }
            $tx->update(['status' => 'successful', 'completed_at' => now()]);

            $label = number_format($amount, 0, ',', ' ') . " {$wallet->currency}";
            Audit::log('wallet.adjust', $wallet, ['direction' => $direction, 'amount' => $amount, 'reason' => $reason, 'transaction' => $tx->reference], $admin->id);
            $this->notify->toAdmins('manual_intervention', 'Intervention manuelle : ' . ($credit ? 'crédit' : 'débit') . " {$label}", "{$wallet->user->full_name} — {$reason} (par {$admin->full_name})", ['severity' => 'warning', 'data' => ['transaction_id' => $tx->id]]);
            $this->notify->toUser($wallet->user, 'adjustment', ($credit ? 'Crédit' : 'Débit') . " de {$label} par FlashPay", $reason, ['sms' => false]);

            return $tx->fresh();
        });
    }
}
