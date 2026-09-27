<?php

namespace App\Services\Agent;

use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;

/**
 * Caisse agent (§3.1.6, §4.6.5) : journal avec solde avant/après, ventilation du
 * float par origine et rapprochement journalier, calculés depuis le ledger.
 */
class AgentLedgerService
{
    /** Net d'une transaction sur le compte wallet:{id} (crédits − débits). */
    protected function netByTransaction(Wallet $w, ?Carbon $from = null, ?Carbon $to = null)
    {
        $q = LedgerEntry::where('account', "wallet:{$w->id}");
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        if ($to) {
            $q->where('created_at', '<', $to);
        }
        return $q->selectRaw("transaction_id, SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) AS net, MAX(created_at) AS at")
            ->groupBy('transaction_id')->orderByDesc('at')->orderByDesc('transaction_id')->get();
    }

    /** Journal paginé du plus récent au plus ancien, avec soldes avant / après. */
    public function journal(Wallet $w, int $limit = 50, ?string $type = null): array
    {
        $w = $w->fresh();
        $rows = $this->netByTransaction($w);
        $balance = (int) $w->balance;
        $txs = Transaction::whereIn('id', $rows->pluck('transaction_id'))->get()->keyBy('id');
        $out = [];
        foreach ($rows as $r) {
            $tx = $txs[$r->transaction_id] ?? null;
            $net = (int) $r->net;
            $line = [
                'transaction_id' => $r->transaction_id,
                'reference' => $tx?->reference,
                'type' => $tx?->type,
                'label' => $this->label($tx),
                'counterparty' => $tx?->meta['client_name'] ?? $tx?->meta['beneficiary_name'] ?? $tx?->destination_account ?? null,
                'amount' => abs($net),
                'direction' => $net >= 0 ? 'credit' : 'debit',
                'balance_after' => $balance,
                'balance_before' => $balance - $net,
                'commission' => (int) ($tx?->meta['agent_commission'] ?? 0),
                'status' => $tx?->status,
                'created_at' => $r->at,
            ];
            $balance -= $net;
            if (! $type || $line['type'] === $type) {
                $out[] = $line;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Ventilation du float par origine (sur une période, 30 jours par défaut). */
    public function breakdown(Wallet $w, ?Carbon $from = null): array
    {
        $w = $w->fresh();
        $from ??= now()->subDays(30);
        $entries = LedgerEntry::where('account', "wallet:{$w->id}")->where('created_at', '>=', $from)->get();
        $bucket = fn ($e) => match (true) {
            str_contains((string) $e->memo, 'Commission') => 'commissions',
            str_contains((string) $e->memo, 'Approvisionnement') => 'approvisionnements',
            str_contains((string) $e->memo, 'espèces remises') || str_contains((string) $e->memo, 'Retrait') => 'retours_cash_out',
            $e->type === 'credit' => 'transferts_entrants',
            default => 'sorties',
        };
        $sum = ['approvisionnements' => 0, 'retours_cash_out' => 0, 'transferts_entrants' => 0, 'commissions' => 0, 'sorties' => 0];
        foreach ($entries as $e) {
            $sum[$bucket($e)] += (int) $e->amount;
        }
        return ['from' => $from->toDateString(), 'balance' => (int) $w->balance, 'currency' => $w->currency, 'origins' => $sum];
    }

    /** Rapprochement journalier : solde d'ouverture + mouvements = solde de clôture. */
    public function reconciliation(Wallet $w, Carbon $day): array
    {
        $w = $w->fresh();
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->addDay()->startOfDay();
        $after = LedgerEntry::where('account', "wallet:{$w->id}")->where('created_at', '>=', $end)
            ->selectRaw("COALESCE(SUM(CASE WHEN type='credit' THEN amount ELSE -amount END),0) AS net")->value('net');
        $closing = (int) $w->balance - (int) $after;

        $dayEntries = LedgerEntry::where('account', "wallet:{$w->id}")->where('created_at', '>=', $start)->where('created_at', '<', $end)->get();
        $credits = (int) $dayEntries->where('type', 'credit')->sum('amount');
        $debits = (int) $dayEntries->where('type', 'debit')->sum('amount');
        $opening = $closing - $credits + $debits;

        $txIds = $dayEntries->pluck('transaction_id')->unique();
        $byType = Transaction::whereIn('id', $txIds)->where('status', 'successful')->get()->groupBy('type')
            ->map(fn ($g) => ['count' => $g->count(), 'volume' => (int) $g->sum('amount'), 'commissions' => (int) $g->sum(fn ($t) => $t->meta['agent_commission'] ?? 0)]);
        $pending = Transaction::whereIn('id', $txIds)->where('status', 'processing')->count();

        return [
            'date' => $start->toDateString(),
            'currency' => $w->currency,
            'opening_balance' => $opening,
            'credits' => $credits,
            'debits' => $debits,
            'closing_balance' => $closing,
            'balanced' => $opening + $credits - $debits === $closing,
            'operations' => $byType,
            'pending_operations' => $pending,
            // Espèces théoriques en caisse : cash reçu (cash-in) − cash remis (cash-out)
            'cash_received' => (int) ($byType['cash_in']['volume'] ?? 0),
            'cash_given' => (int) (($byType['cash_pickup']['volume'] ?? 0) + ($byType['cash_out']['volume'] ?? 0)),
        ];
    }

    protected function label(?Transaction $tx): string
    {
        return match ($tx?->type) {
            'cash_in' => 'Recharge client',
            'cash_pickup', 'cash_out' => 'Retrait client',
            'float_topup' => 'Approvisionnement',
            'p2p' => 'Transfert wallet',
            'withdrawal' => 'Vers compte externe',
            'adjustment' => 'Ajustement FlashPay',
            default => (string) ($tx?->type ?? 'Opération'),
        };
    }
}
