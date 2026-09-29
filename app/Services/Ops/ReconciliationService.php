<?php

namespace App\Services\Ops;

use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\PeexRequest;
use App\Models\ReconciliationReport;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Réconciliation automatique (§13.1, §5 Auditabilité) :
 *  1. équilibre du ledger (débits = crédits par transaction et devise) ;
 *  2. cohérence solde wallet ↔ ledger ;
 *  3. opérations bloquées (timeout opérateur) → « en cours de vérification » ;
 *  4. écritures orphelines PEEX (succès opérateur / échec FlashPay et inversement).
 * Toute anomalie alerte l'administrateur (§11.4).
 */
class ReconciliationService
{
    public function __construct(protected NotificationService $notify)
    {
    }

    public function run(?Carbon $day = null): ReconciliationReport
    {
        $day ??= now();
        $results = [
            'unbalanced_transactions' => $this->unbalanced(),
            'wallet_mismatches' => $this->walletMismatches(),
            'stuck_transactions' => $this->stuck(),
            'peex_orphans' => $this->peexOrphans(),
        ];
        $count = array_sum(array_map('count', $results));

        $report = ReconciliationReport::create(['report_date' => $day->toDateString(), 'anomalies' => $count, 'results' => $results]);

        if ($count > 0) {
            $this->notify->toAdmins('reconciliation_anomaly', "Réconciliation : {$count} anomalie(s) détectée(s)", collect($results)->map(fn ($v, $k) => count($v) . ' ' . str_replace('_', ' ', $k))->filter(fn ($s) => ! str_starts_with($s, '0 '))->implode(', '), [
                'severity' => $results['peex_orphans'] || $results['unbalanced_transactions'] ? 'critical' : 'warning',
                'data' => ['report_id' => $report->id],
            ]);
        }
        return $report;
    }

    protected function unbalanced(): array
    {
        return LedgerEntry::query()
            ->selectRaw("transaction_id, currency, SUM(CASE WHEN type='debit' THEN amount ELSE 0 END) AS debits, SUM(CASE WHEN type='credit' THEN amount ELSE 0 END) AS credits")
            ->groupBy('transaction_id', 'currency')
            ->havingRaw("SUM(CASE WHEN type='debit' THEN amount ELSE 0 END) <> SUM(CASE WHEN type='credit' THEN amount ELSE 0 END)")
            ->limit(200)->get()->map(fn ($r) => ['transaction_id' => $r->transaction_id, 'currency' => $r->currency, 'debits' => (int) $r->debits, 'credits' => (int) $r->credits])->all();
    }

    /** Solde attendu = somme des mouvements du ledger (+ solde d'ouverture initial éventuel). */
    protected function walletMismatches(): array
    {
        $net = LedgerEntry::where('account', 'like', 'wallet:%')
            ->selectRaw("account, SUM(CASE WHEN type='credit' THEN amount ELSE -amount END) AS net")
            ->groupBy('account')->pluck('net', 'account');
        $opening = app(PlatformSettings::class)->get('opening_balances', []);

        $out = [];
        Wallet::select('id', 'user_id', 'balance', 'currency')->chunk(500, function ($wallets) use ($net, $opening, &$out) {
            foreach ($wallets as $w) {
                $expected = (int) ($net["wallet:{$w->id}"] ?? 0) + (int) ($opening[$w->id] ?? 0);
                if ($expected !== (int) $w->balance && count($out) < 200) {
                    $out[] = ['wallet_id' => $w->id, 'user_id' => $w->user_id, 'balance' => (int) $w->balance, 'ledger' => $expected, 'gap' => (int) $w->balance - $expected, 'currency' => $w->currency];
                }
            }
        });
        return $out;
    }

    /** Transactions en attente opérateur au-delà du délai : statut explicite « en cours de vérification ». */
    protected function stuck(): array
    {
        $limit = now()->subMinutes(config('security.verification_after_minutes', 15));
        return Transaction::where('status', 'processing')->where('created_at', '<', $limit)
            ->where(fn ($q) => $q->whereNull('stage')->orWhereNotIn('stage', ['awaiting_pickup', 'awaiting_bank']))
            ->limit(200)->get()->map(function (Transaction $t) {
                if (empty($t->meta['verification_required'])) {
                    $t->update(['meta' => ($t->meta ?? []) + ['verification_required' => true, 'verification_since' => now()->toIso8601String()]]);
                }
                return ['transaction_id' => $t->id, 'reference' => $t->reference, 'stage' => $t->stage, 'since' => $t->created_at?->toIso8601String()];
            })->all();
    }

    protected function peexOrphans(): array
    {
        $out = [];
        PeexRequest::with('transaction')->whereNotNull('transaction_id')->where('updated_at', '>=', now()->subDays(7))->chunk(300, function ($reqs) use (&$out) {
            foreach ($reqs as $r) {
                $peex = PeexRequest::normalize($r->status);
                $tx = $r->transaction;
                if (! $tx) {
                    continue;
                }
                $leg = \App\Services\Connectors\PeexConnector::legOf($r->track_id);
                $bad = match ($leg) {
                    // débité chez l'opérateur mais transaction en échec sans remboursement
                    'C' => $peex === 'successful' && $tx->status === 'failed' && $tx->source_rail === 'peex',
                    // bénéficiaire payé alors que le client a été remboursé (double paiement) / ou l'inverse
                    'D' => ($peex === 'successful' && $tx->status === 'reversed')
                        || ($peex === 'failed' && $tx->status === 'successful'),
                    // remboursement payé mais transaction non remboursée
                    'R' => $peex === 'successful' && $tx->status !== 'reversed' && $tx->stage !== 'awaiting_refund',
                    default => false,
                };
                if ($bad && count($out) < 200) {
                    $out[] = ['peex_request_id' => $r->id, 'track_id' => $r->track_id, 'service' => $r->service, 'peex_status' => $r->status, 'transaction' => $tx->reference, 'transaction_status' => $tx->status];
                }
            }
        });
        return $out;
    }

    /** Rapports quotidiens : rapprochement agent (§11.2) et réconciliation marchand par point de vente (§11.3). */
    public function dailyReports(?Carbon $day = null): array
    {
        $day ??= now();
        $agents = 0;
        $merchants = 0;
        \App\Models\Agent::with('user.wallet')->where('validation_status', 'approved')->each(function ($a) use ($day, &$agents) {
            if (! $a->user?->wallet) {
                return;
            }
            $r = app(\App\Services\Agent\AgentLedgerService::class)->reconciliation($a->user->wallet, $day);
            if ($r['credits'] + $r['debits'] > 0) {
                $this->notify->toUser($a->user, 'daily_reconciliation', 'Rapprochement du ' . $day->format('d/m') . ' disponible', 'Clôture : ' . number_format($r['closing_balance'], 0, ',', ' ') . " {$r['currency']}", ['sms' => false, 'data' => ['date' => $r['date']]]);
                $agents++;
            }
        });
        Merchant::with('user.wallet')->where('validation_status', 'approved')->each(function ($m) use ($day, &$merchants) {
            $wid = $m->user?->wallet?->id;
            if (! $wid) {
                return;
            }
            $q = Transaction::where('destination_wallet_id', $wid)->where('status', 'successful')->whereDate('created_at', $day->toDateString());
            $count = (clone $q)->count();
            if ($count > 0) {
                $total = (int) (clone $q)->sum(DB::raw('COALESCE(destination_amount, amount) - merchant_fee'));
                $this->notify->toUser($m->user, 'daily_reconciliation', 'Réconciliation du ' . $day->format('d/m') . ' disponible', "{$count} encaissement(s), net " . number_format($total, 0, ',', ' '), ['sms' => false, 'data' => ['date' => $day->toDateString()]]);
                $merchants++;
            }
        });
        return ['agents' => $agents, 'merchants' => $merchants];
    }
}
