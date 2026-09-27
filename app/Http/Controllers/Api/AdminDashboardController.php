<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\TransactionChannels;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Données agrégées du tableau de bord Super Admin (une seule requête HTTP).
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $today = CarbonImmutable::today();
        [$rFrom, $rTo] = $this->range($request);
        if ($rFrom || $rTo) {
            // Période personnalisée « du … au … » (max. 366 jours)
            $to = $rTo ?? $today;
            $from = $rFrom ?? $to->subDays(29);
            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            if ($from->diffInDays($to) > 365) {
                $from = $to->subDays(365);
            }
        } else {
            $days = min(max((int) $request->query('days', 14), 1), 365);
            $from = $today->subDays($days - 1);
            $to = $today;
        }
        $days = (int) $from->diffInDays($to) + 1;
        $end = $to->addDay(); // borne exclusive

        $period = Transaction::where('created_at', '>=', $from)->where('created_at', '<', $end);
        $todayQ = Transaction::where('created_at', '>=', $today);

        // --- KPI globaux (Nombre, Volume, Réussies, Échouées, Rejetées, En attente) ---
        $tot = $this->stats((clone $period));
        $fees = (int) (clone $period)->where('status', 'successful')->sum('fee');
        $finished = $tot['successful'] + $tot['failed'] + $tot['reversed'];

        // --- Série journalière ---
        $rows = (clone $period)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c, SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as v', ['successful'])
            ->groupBy('d')->get()->keyBy(fn ($r) => (string) $r->d);

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->addDays($i)->toDateString();
            $series[] = ['date' => $d, 'count' => (int) ($rows[$d]->c ?? 0), 'volume' => (int) ($rows[$d]->v ?? 0)];
        }

        // --- Par famille / canal : Retraits, Recharges, Paiements, Transferts ---
        $expr = TransactionChannels::sql();
        $byChannel = $this->statsSelect((clone $period))
            ->selectRaw("{$expr} as ch")
            ->groupBy('ch')->get()->keyBy('ch');

        $families = [];
        foreach (TransactionChannels::FAMILIES as $fk => $f) {
            $channels = [];
            foreach ($f['channels'] as $ch) {
                $channels[] = ['key' => $ch, 'label' => TransactionChannels::LABELS[$ch]] + $this->row($byChannel[$ch] ?? null);
            }
            $sum = [];
            foreach (['count', 'volume', 'volume_successful', 'successful', 'failed', 'reversed', 'processing'] as $k) {
                $sum[$k] = array_sum(array_column($channels, $k));
            }
            $families[] = ['key' => $fk, 'label' => $f['label']] + $sum + ['channels' => $channels];
        }

        // --- PEEX ---
        $peex = PeexRequest::where('created_at', '>=', $from)->where('created_at', '<', $end)
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'period' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'custom' => (bool) ($rFrom || $rTo)],
            'kpi' => $tot + [
                'transactions' => $tot['count'],
                'fees' => $fees,
                'success_rate' => $finished ? round($tot['successful'] * 100 / $finished, 1) : null,
                'processing_all' => Transaction::where('status', 'processing')->count(),
                'today_transactions' => (clone $todayQ)->count(),
                'today_volume' => (int) (clone $todayQ)->where('status', 'successful')->sum('amount'),
                'wallets_balance' => (int) Wallet::sum('balance'),
            ],
            'statuses' => TransactionChannels::STATUSES,
            'users' => [
                'total' => User::count(),
                'by_role' => User::query()
                    ->join('model_has_roles', function ($j) {
                        $j->on('users.id', '=', 'model_has_roles.model_id')->where('model_has_roles.model_type', User::class);
                    })
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->selectRaw('roles.name as role, COUNT(*) as c')->groupBy('roles.name')->pluck('c', 'role'),
                'kyc_pending' => User::whereIn('kyc_status', ['pending', 'submitted'])->count(),
                'active' => User::where('status', 'active')->count(),
                'inactive' => User::where('status', '<>', 'active')->count(),
            ],
            'pending' => [
                'merchants' => Merchant::where('validation_status', 'pending')->count(),
                'agents' => Agent::where('validation_status', 'pending')->count(),
            ],
            'series' => $series,
            'performance' => $this->performance($from, $end, $days, $families),
            'families' => $families,
            'peex' => [
                'sandbox' => (bool) config('flashpay.peex.sandbox'),
                'by_status' => $peex,
                'awaiting' => PeexRequest::whereNull('finalized_at')->whereIn('status', PeexRequest::PENDING_STATUSES)->count(),
                'last_error' => PeexRequest::where('status', 'error')->latest()->value('message'),
            ],
            'recent' => Transaction::latest()->limit(8)
                ->with(['sourceWallet.user:id,full_name,phone', 'destinationWallet.user:id,full_name,phone'])
                ->get()->map(fn ($t) => $this->withParties($t)->only(['id', 'reference', 'type', 'source_rail', 'destination_rail', 'amount', 'currency', 'status', 'created_at', 'sender', 'beneficiary'])),
        ]);
    }

    /** Expéditeur / bénéficiaire lisibles (nom + numéro ou compte). Relations wallet.user préchargées. */
    private function withParties(Transaction $t): Transaction
    {
        $m = $t->meta ?? [];
        $src = $t->sourceWallet?->user;
        $dst = $t->destinationWallet?->user;
        $t->sender = [
            'name' => $src?->full_name ?? $m['sender_name'] ?? $m['payer_name'] ?? ($t->source_rail === 'treasury' ? 'FlashPay (trésorerie)' : null),
            'account' => \App\Support\Phone::display($t->source_account ?? $src?->phone) ?? ($t->source_rail === 'wallet' ? null : strtoupper((string) $t->source_rail)),
        ];
        $t->beneficiary = [
            'name' => $m['merchant_name'] ?? $dst?->full_name ?? $m['beneficiary_name'] ?? $m['client_name'] ?? ($t->destination_rail === 'treasury' ? 'FlashPay (trésorerie)' : null),
            'account' => ($t->destination_rail === 'bank' ? $t->destination_account : \App\Support\Phone::display($t->destination_account ?? $dst?->phone)) ?? ($m['bank_name'] ?? null),
        ];
        $t->unsetRelations();
        return $t;
    }

    /**
     * Liste admin de TOUTES les transactions, filtrable :
     * ?channel=payments|interop|withdrawals|qr|atm…  ?status=  ?q=référence/numéro  ?days=
     */
    public function transactions(Request $request)
    {
        $q = Transaction::query()->latest();
        $expr = TransactionChannels::sql();

        if ($ch = $request->query('channel')) {
            $list = TransactionChannels::expand($ch);
            $q->whereIn(\Illuminate\Support\Facades\DB::raw($expr), $list ?: ['__none__']);
        }
        if ($status = $request->query('status')) {
            $q->whereIn('status', array_intersect(explode(',', $status), array_keys(TransactionChannels::STATUSES)) ?: ['__none__']);
        }
        [$from, $to] = $this->range($request);
        if ($from || $to) {
            // Filtre « du … au … » (dates incluses)
            if ($from && $to && $from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
            $from && $q->where('created_at', '>=', $from);
            $to && $q->where('created_at', '<', $to->addDay());
        } elseif ($days = (int) $request->query('days')) {
            $q->where('created_at', '>=', CarbonImmutable::today()->subDays(min(max($days, 1), 365) - 1));
        }
        if ($uid = (int) $request->query('user')) {
            // Transactions d'un client / agent (wallet source ou destination, ou initiées)
            $u = User::with('wallet')->find($uid);
            $wid = $u?->wallet?->id ?? 0;
            $q->where(fn ($w) => $w->where('initiated_by', $uid)->orWhere('source_wallet_id', $wid)->orWhere('destination_wallet_id', $wid));
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%{$term}%")
                ->orWhere('source_account', 'like', "%{$term}%")
                ->orWhere('destination_account', 'like', "%{$term}%"));
        }

        $page = $q->select('transactions.*')->selectRaw("{$expr} as channel")
            ->with(['sourceWallet.user:id,full_name,phone', 'destinationWallet.user:id,full_name,phone'])
            ->paginate(25)->withQueryString();
        $page->getCollection()->transform(function ($t) {
            $t->channel_label = TransactionChannels::LABELS[$t->channel] ?? $t->channel;
            return $this->withParties($t);
        });

        return response()->json($page->toArray() + ['channels' => TransactionChannels::LABELS, 'families' => TransactionChannels::FAMILIES, 'statuses' => TransactionChannels::STATUSES,
            'user_name' => ($uid = (int) $request->query('user')) ? User::whereKey($uid)->value('full_name') : null]);
    }

    /**
     * Indicateurs de performance : période courante vs période précédente de même durée,
     * performance par activité, top marchands et top agents.
     */
    private function performance(CarbonImmutable $from, CarbonImmutable $end, int $days, array $families): array
    {
        $pFrom = $from->subDays($days);
        $in = fn ($a, $b) => Transaction::where('transactions.created_at', '>=', $a)->where('transactions.created_at', '<', $b);

        $kpi = function ($a, $b) use ($in) {
            $st = $this->stats($in($a, $b));
            $done = $st['successful'] + $st['failed'] + $st['reversed'];
            $fees = (int) $in($a, $b)->where('status', 'successful')->sum('fee');
            $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
            $delay = $driver === 'sqlite'
                ? "AVG((julianday(completed_at) - julianday(created_at)) * 86400)"
                : "AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at))";
            return [
                'transactions' => $st['count'],
                'volume' => $st['volume_successful'],
                'success_rate' => $done ? round($st['successful'] * 100 / $done, 1) : null,
                'avg_ticket' => $st['successful'] ? (int) round($st['volume_successful'] / $st['successful']) : null,
                'fees' => $fees,
                'active_users' => $in($a, $b)->whereNotNull('initiated_by')->distinct()->count('initiated_by'),
                'new_accounts' => User::where('created_at', '>=', $a)->where('created_at', '<', $b)->count(),
                'avg_delay' => ($v = $in($a, $b)->where('status', 'successful')->whereNotNull('completed_at')->selectRaw("{$delay} as d")->value('d')) === null ? null : (int) round($v),
            ];
        };
        $cur = $kpi($from, $end);
        $prev = $kpi($pFrom, $from);

        // Volume réussi par famille sur la période précédente (pour l'évolution)
        $expr = TransactionChannels::sql();
        $prevByCh = $in($pFrom, $from)->where('status', 'successful')
            ->selectRaw("{$expr} as ch, SUM(amount) as v")->groupBy('ch')->pluck('v', 'ch');
        $byFamily = [];
        foreach ($families as $f) {
            $done = $f['successful'] + $f['failed'] + $f['reversed'];
            $prevVol = (int) collect(TransactionChannels::FAMILIES[$f['key']]['channels'])->sum(fn ($c) => $prevByCh[$c] ?? 0);
            $byFamily[] = [
                'key' => $f['key'], 'label' => $f['label'],
                'avg_ticket' => $f['successful'] ? (int) round($f['volume_successful'] / $f['successful']) : null,
                'success_rate' => $done ? round($f['successful'] * 100 / $done, 1) : null,
                'volume' => $f['volume_successful'],
                'prev_volume' => $prevVol,
            ];
        }

        // Top 5 marchands (paiements reçus)
        $pay = ['qr_payment', 'merchant_payment', 'nfc_payment', 'manual_payment', 'collection'];
        $merchants = $in($from, $end)->whereIn('transactions.type', $pay)
            ->join('wallets', 'wallets.id', '=', 'transactions.destination_wallet_id')
            ->join('merchants', 'merchants.user_id', '=', 'wallets.user_id')
            ->groupBy('merchants.id', 'merchants.business_name')
            ->selectRaw("merchants.id, merchants.business_name as name, COUNT(*) as c,
                SUM(CASE WHEN transactions.status = 'successful' THEN transactions.amount ELSE 0 END) as v,
                SUM(CASE WHEN transactions.status = 'successful' THEN 1 ELSE 0 END) as ok")
            ->orderByDesc('v')->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'count' => (int) $r->c, 'volume' => (int) $r->v, 'success_rate' => $r->c ? round($r->ok * 100 / $r->c, 1) : null]);

        // Top 5 agents : dépôts (cash_in, wallet source = agent), retraits au comptoir (cash_out)
        // et retraits par QR code / bon de retrait (cash_pickup, wallet destination = agent)
        $agents = $in($from, $end)->whereIn('transactions.type', ['cash_in', 'cash_out', 'cash_pickup'])
            ->join('wallets', 'wallets.id', '=', \Illuminate\Support\Facades\DB::raw("CASE WHEN transactions.type = 'cash_in' THEN transactions.source_wallet_id ELSE transactions.destination_wallet_id END"))
            ->join('agents', 'agents.user_id', '=', 'wallets.user_id')
            ->join('users', 'users.id', '=', 'agents.user_id')
            ->groupBy('agents.id', 'users.full_name', 'agents.zone')
            ->selectRaw("agents.id, users.full_name as name, agents.zone,
                SUM(CASE WHEN transactions.type = 'cash_in' THEN 1 ELSE 0 END) as dep,
                SUM(CASE WHEN transactions.type = 'cash_out' THEN 1 ELSE 0 END) as wd,
                SUM(CASE WHEN transactions.type = 'cash_pickup' THEN 1 ELSE 0 END) as qr,
                SUM(CASE WHEN transactions.status = 'successful' THEN transactions.amount ELSE 0 END) as v")
            ->orderByDesc('v')->limit(5)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'zone' => $r->zone, 'deposits' => (int) $r->dep, 'withdrawals' => (int) $r->wd, 'qr_withdrawals' => (int) $r->qr, 'volume' => (int) $r->v]);

        return [
            'previous' => ['from' => $pFrom->toDateString(), 'to' => $from->subDay()->toDateString()],
            'current' => $cur,
            'prev' => $prev,
            'by_family' => $byFamily,
            'top_merchants' => $merchants,
            'top_agents' => $agents,
        ];
    }

    /** Dates ?from=AAAA-MM-JJ&to=AAAA-MM-JJ (invalides ignorées). @return array{0:?CarbonImmutable,1:?CarbonImmutable} */
    private function range(Request $request): array
    {
        $parse = function ($v) {
            if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $v)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };
        return [$parse($request->query('from')), $parse($request->query('to'))];
    }

    /** Colonnes agrégées communes : nombre, volumes et répartition par statut. */
    private function statsSelect($q)
    {
        return $q->selectRaw("COUNT(*) as c, COALESCE(SUM(amount),0) as v,
            COALESCE(SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END),0) as vok,
            COALESCE(SUM(CASE WHEN status = 'successful' THEN 1 ELSE 0 END),0) as ok,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END),0) as ko,
            COALESCE(SUM(CASE WHEN status = 'reversed' THEN 1 ELSE 0 END),0) as rj,
            COALESCE(SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END),0) as p");
    }

    private function stats($q): array
    {
        return $this->row($this->statsSelect($q)->first());
    }

    private function row($r): array
    {
        return [
            'count' => (int) ($r->c ?? 0),
            'volume' => (int) ($r->v ?? 0),
            'volume_successful' => (int) ($r->vok ?? 0),
            'successful' => (int) ($r->ok ?? 0),
            'failed' => (int) ($r->ko ?? 0),
            'reversed' => (int) ($r->rj ?? 0),
            'processing' => (int) ($r->p ?? 0),
        ];
    }
}
