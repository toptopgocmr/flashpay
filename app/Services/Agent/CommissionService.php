<?php

namespace App\Services\Agent;

use App\Models\CommissionRule;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\WalletService;

/**
 * Barème de commissions agents (§3.1.7) par type d'opération et palier de montant.
 *   type fixed     : montant fixe
 *   type percent   : % du montant de l'opération
 *   type fee_share : % des frais payés par le client
 * Sans règle active : repli sur config payment_methods.agent_commission_percent (partage des frais).
 */
class CommissionService
{
    public const OPERATIONS = [
        'cash_in' => 'Recharge client (cash-in)',
        'cash_out' => 'Retrait client (cash-out)',
        'external_transfer' => 'Transfert wallet ↔ compte externe',
        'p2p' => 'Transfert wallet → wallet',
        'float_request' => 'Approvisionnement d\'un sous-agent (super-agent)',
    ];

    public function __construct(protected WalletService $wallets, protected LedgerService $ledger)
    {
    }

    public function compute(string $operation, int $amount, int $fee = 0): int
    {
        $rule = CommissionRule::where('operation', $operation)->where('active', true)
            ->where('min_amount', '<=', $amount)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->orderByDesc('min_amount')->first();

        if (! $rule) {
            return $operation === 'cash_out' ? (int) floor($fee * config('payment_methods.agent_commission_percent', 50) / 100) : 0;
        }

        return max(0, (int) floor(match ($rule->type) {
            'fixed' => $rule->value,
            'percent' => $amount * $rule->value / 100,
            'fee_share' => $fee * $rule->value / 100,
            default => 0,
        }));
    }

    /**
     * Crédite la commission sur le wallet de l'agent. Partie financée par les frais
     * client (flashpay:fees) ou, à défaut, charge de commissions FlashPay.
     */
    public function pay(Transaction $tx, Wallet $agentWallet, int $commission, string $operation): int
    {
        if ($commission <= 0) {
            return 0;
        }
        $this->wallets->credit($agentWallet, $commission);
        $from = $tx->fee > 0 ? 'flashpay:fees' : 'flashpay:commissions';
        $this->ledger->recordDoubleEntry($tx, $from, "wallet:{$agentWallet->id}", $commission, null, 'Commission agent — ' . (self::OPERATIONS[$operation] ?? $operation));
        $tx->update(['meta' => ($tx->meta ?? []) + ['agent_commission' => $commission]]);

        app(\App\Services\Notifications\NotificationService::class)->toUser($agentWallet->user, 'commission', 'Commission créditée : ' . number_format($commission, 0, ',', ' ') . ' ' . $agentWallet->currency, self::OPERATIONS[$operation] ?? null, ['severity' => 'success', 'sms' => false, 'data' => ['transaction_id' => $tx->id]]);

        return $commission;
    }
}
