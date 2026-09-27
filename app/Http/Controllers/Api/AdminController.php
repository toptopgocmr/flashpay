<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Merchant;
use App\Models\Tariff;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Endpoints réservés au Super Admin (cf. §2).
 */
class AdminController extends Controller
{
    public function users(Request $request)
    {
        // Équipe interne uniquement (Super Admin, Support)
        return response()->json(User::with('roles')->whereHas('roles', fn ($r) => $r->whereIn('name', ['super_admin', 'support']))->latest()->paginate(30));
    }

    public function createInternalUser(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string',
            'phone' => 'required|string|unique:users,phone',
            'password' => 'required|string|min:6',
            'role' => 'required|in:super_admin,support,agent',
        ]);

        $user = User::create([
            'full_name' => $validated['full_name'],
            'phone' => $validated['phone'],
            'password' => bcrypt($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        if ($validated['role'] === 'agent') {
            Agent::create(['user_id' => $user->id, 'validation_status' => 'approved', 'validated_at' => now()]);
        }

        return response()->json($user, 201);
    }

    /** Liste complète des marchands : ?status=pending|approved|rejected&q= */
    public function merchants(Request $request)
    {
        $q = Merchant::with(['user.wallet', 'settlementAccounts'])->withCount(['cashiers', 'outlets'])->latest();
        if ($s = $request->query('status')) {
            $q->where('validation_status', $s);
        }
        if ($c = $request->query('country')) {
            $q->where('country', strtoupper($c));
        }
        if ($city = $request->query('city')) {
            $q->where('city', $city);
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('business_name', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u->where('full_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")));
        }

        return response()->json([
            'data' => $q->limit(200)->get()->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'business_name' => $m->business_name,
                'category' => $m->business_category,
                'country' => $m->country,
                'city' => $m->city,
                'address' => $m->address,
                'owner' => $m->user?->full_name,
                'phone' => $m->user?->phone,
                'code' => $m->qr_code_token,
                'channels' => $m->settlementAccounts->map(fn ($a) => ['type' => $a->type, 'is_default' => (bool) $a->is_default])->values(),
                'status' => $m->validation_status,
                'balance' => (int) ($m->user?->wallet?->balance ?? 0),
                'currency' => $m->user?->wallet?->currency ?? 'XAF',
                'cashiers' => (int) $m->cashiers_count,
                'outlets' => (int) $m->outlets_count,
                'created_at' => $m->created_at,
            ]),
            'counts' => Merchant::selectRaw('validation_status as s, COUNT(*) as c')->groupBy('s')->pluck('c', 's'),
            'by_country' => Merchant::selectRaw('country as k, COUNT(*) as c')->groupBy('k')->pluck('c', 'k'),
        ]);
    }

    /** Liste complète des agents : ?status=&q= */
    public function agents(Request $request)
    {
        $q = Agent::with(['user.wallet', 'parent.user:id,full_name'])->withCount('subAgents')->latest();
        if ($s = $request->query('status')) {
            $q->where('validation_status', $s);
        }
        // Niveau dans le réseau : super (super-agents) | sub (sous-agents) | simple
        match ($request->query('level')) {
            'super' => $q->where('is_super_agent', true),
            'sub' => $q->whereNotNull('parent_agent_id'),
            'simple' => $q->where('is_super_agent', false)->whereNull('parent_agent_id'),
            default => null,
        };
        if ($c = $request->query('country')) {
            $q->where('country', strtoupper($c));
        }
        if ($city = $request->query('city')) {
            $q->where('city', $city);
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->whereHas('user', fn ($u) => $u->where('full_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
        }

        return response()->json([
            'data' => $q->limit(200)->get()->map(fn ($a) => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'name' => $a->user?->full_name,
                'phone' => $a->user?->phone,
                'zone' => $a->zone,
                'country' => $a->country ?? $a->user?->wallet?->country,
                'city' => $a->city,
                'status' => $a->validation_status,
                'account_status' => $a->user?->status,
                'active' => $a->user?->status === 'active',
                'wallet_status' => $a->user?->wallet?->status ?? 'active',
                'float' => (int) ($a->user?->wallet?->balance ?? 0),
                'currency' => $a->user?->wallet?->currency ?? 'XAF',
                'agent_code' => $a->agent_code,
                'pos_code' => $a->pos_code,
                'level' => $a->is_super_agent ? 'super' : ($a->parent_agent_id ? 'sub' : 'simple'),
                'parent_id' => $a->parent_agent_id,
                'parent_name' => $a->parent?->user?->full_name,
                'sub_agents' => (int) $a->sub_agents_count,
                'created_at' => $a->created_at,
            ]),
            'counts' => Agent::selectRaw('validation_status as s, COUNT(*) as c')->groupBy('s')->pluck('c', 's'),
            'levels' => [
                'super' => Agent::where('is_super_agent', true)->count(),
                'sub' => Agent::whereNotNull('parent_agent_id')->count(),
                'simple' => Agent::where('is_super_agent', false)->whereNull('parent_agent_id')->count(),
            ],
            'by_country' => Agent::selectRaw('country as k, COUNT(*) as c')->groupBy('k')->pluck('c', 'k'),
        ]);
    }

    /** Pays (corridors) et villes proposées pour le réseau agents / marchands. */
    public function geo()
    {
        $cities = config('cities', []);
        $out = [];
        foreach (app(\App\Services\Peex\PeexCorridors::class)->all() as $iso => $c) {
            $out[] = ['country' => $iso, 'name' => $c['name'], 'zone' => $c['zone'], 'currency' => $c['currency'], 'dial' => '+' . $c['dial'], 'cities' => $cities[$iso] ?? []];
        }
        return response()->json(['countries' => $out]);
    }

    /**
     * Crée (ou active) un compte marchand. Si le numéro a déjà un compte
     * FlashPay (client…), le profil marchand y est ajouté sans changer le mot de passe.
     */
    public function createMerchant(Request $request)
    {
        $v = $request->validate([
            'full_name' => 'required|string|max:150',
            'phone' => 'required|string|max:25',
            'password' => 'nullable|string|min:4',
            'business_name' => 'required|string|max:150',
            'business_category' => 'nullable|string|max:80',
            'country' => 'required|string|size:2',
            'city' => 'required|string|max:80',
            'address' => 'nullable|string|max:190',
            'settlement_phone' => 'nullable|string|max:25',
            'settlement' => 'nullable|array',
            'settlement.type' => 'nullable|in:mobile_money,bank,wallet,cash_pickup',
            // Multicanal : plusieurs modes de retrait / règlement, l'un marqué par défaut
            'settlements' => 'nullable|array|max:8',
            'settlements.*.type' => 'required|in:mobile_money,bank,wallet,cash_pickup',
            'settlements.*.is_default' => 'nullable|boolean',
        ]);

        $accounts = $request->filled('settlements')
            ? array_values((array) $request->input('settlements'))
            : [(array) $request->input('settlement', [])];
        if (! collect($accounts)->contains(fn ($a) => ! empty($a['is_default']))) {
            $accounts[0]['is_default'] = true;
        }
        [$user, $created, $merchant] = \Illuminate\Support\Facades\DB::transaction(function () use ($v, $request, $accounts) {
            [$user, $created] = $this->accountFor($v, 'merchant');
            if ($user->merchant) {
                abort(response()->json(['message' => 'Ce numéro a déjà un compte marchand.'], 422));
            }

            $merchant = Merchant::create([
                'user_id' => $user->id,
                'business_name' => $v['business_name'],
                'business_category' => $v['business_category'] ?? null,
                'country' => strtoupper($v['country']),
                'city' => $v['city'],
                'address' => $v['address'] ?? null,
                'settlement_phone' => $v['settlement_phone'] ?? $user->phone,
                'qr_code_token' => 'FPM-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12)),
                'validation_status' => 'approved',
                'validated_at' => now(),
                'validated_by' => $request->user()->id,
            ]);

            // Modes de retrait / règlement (mobile money par défaut, + banque / wallet / cash agent)
            foreach ($accounts as $settlement) {
                $acc = array_filter((array) $settlement, fn ($x) => $x !== null && $x !== '') + ['type' => 'mobile_money'];
                if ($acc['type'] === 'mobile_money' || $acc['type'] === 'wallet') {
                    $acc['phone'] = $acc['phone'] ?? $v['settlement_phone'] ?? $user->phone;
                }
                if ($acc['type'] === 'bank' && empty($acc['account_holder'])) {
                    $acc['account_holder'] = $v['business_name'];
                }
                app(\App\Services\SettlementService::class)->addAccount($merchant, $acc + ['country' => $merchant->country, 'is_default' => ! empty($acc['is_default'])]);
            }

            return [$user, $created, $merchant];
        });

        return response()->json([
            'merchant' => $merchant,
            'login' => ['phone' => $user->phone, 'profile' => 'Marchand', 'new_account' => $created],
            'message' => $created ? 'Compte marchand créé.' : 'Profil marchand ajouté au compte existant (mot de passe inchangé).',
        ], 201);
    }

    // ---- Modes de retrait (comptes de règlement) d'un marchand, gérés par le Super Admin ----

    public function merchantAccounts(Merchant $merchant)
    {
        return response()->json($merchant->settlementAccounts()->orderByDesc('is_default')->get()->map->toApi());
    }

    public function addMerchantAccount(Request $request, Merchant $merchant)
    {
        $v = $request->validate([
            'type' => 'required|in:mobile_money,bank,wallet,cash_pickup',
            'label' => 'nullable|string|max:80',
            'phone' => 'nullable|string|max:25',
            'bank_name' => 'nullable|string|max:120',
            'account_holder' => 'nullable|string|max:120',
            'account_number' => 'nullable|string|max:60',
            'swift' => 'nullable|string|max:20',
            'is_default' => 'nullable|boolean',
        ]);
        if (in_array($v['type'], ['mobile_money', 'wallet']) && empty($v['phone'])) {
            $v['phone'] = $merchant->settlement_phone ?: $merchant->user?->phone;
        }
        if ($v['type'] === 'bank' && empty($v['account_holder'])) {
            $v['account_holder'] = $merchant->business_name;
        }
        $acc = app(\App\Services\SettlementService::class)->addAccount($merchant, $v + ['country' => $merchant->country]);

        return response()->json($acc->toApi(), 201);
    }

    public function defaultMerchantAccount(Merchant $merchant, \App\Models\SettlementAccount $account)
    {
        abort_unless($account->merchant_id === $merchant->id, 404);
        app(\App\Services\SettlementService::class)->setDefault($account);

        return response()->json(['message' => 'Mode de retrait par défaut modifié.']);
    }

    public function deleteMerchantAccount(Merchant $merchant, \App\Models\SettlementAccount $account)
    {
        abort_unless($account->merchant_id === $merchant->id, 404);
        if ($merchant->settlementAccounts()->count() <= 1) {
            return response()->json(['message' => 'Le marchand doit garder au moins un mode de retrait.'], 422);
        }
        $wasDefault = $account->is_default;
        $account->delete();
        if ($wasDefault && ($next = $merchant->settlementAccounts()->first())) {
            app(\App\Services\SettlementService::class)->setDefault($next);
        }

        return response()->json(['message' => 'Mode de retrait supprimé.']);
    }

    /** Crée (ou active) un agent FlashPay : dépôts d'espèces et retraits cash. */
    public function createAgent(Request $request)
    {
        $v = $request->validate([
            'full_name' => 'required|string|max:150',
            'phone' => 'required|string|max:25',
            'password' => 'nullable|string|min:4',
            'zone' => 'nullable|string|max:120',
            'country' => 'required|string|size:2',
            'city' => 'required|string|max:80',
        ]);

        [$user, $created] = $this->accountFor($v, 'agent');
        if ($user->agent) {
            return response()->json(['message' => 'Ce numéro a déjà un compte agent.'], 422);
        }

        $agent = Agent::create([
            'user_id' => $user->id,
            'zone' => $v['zone'] ?? null,
            'country' => strtoupper($v['country']),
            'city' => $v['city'],
            'validation_status' => 'approved',
            'validated_at' => now(),
        ]);

        return response()->json([
            'agent' => $agent,
            'login' => ['phone' => $user->phone, 'profile' => 'Agent', 'new_account' => $created],
            'message' => $created ? 'Compte agent créé.' : 'Profil agent ajouté au compte existant (mot de passe inchangé).',
        ], 201);
    }

    /**
     * Approvisionne le float électronique d'un agent (l'agent a versé l'équivalent
     * en espèces / virement à FlashPay). Écriture : flashpay:float -> wallet agent.
     */
    public function fundAgent(Request $request, Agent $agent)
    {
        $v = $request->validate(['amount' => 'required|integer|min:100', 'note' => 'nullable|string|max:140']);
        $wallet = $agent->user?->wallet ?? abort(422, 'Wallet agent introuvable.');

        $tx = \Illuminate\Support\Facades\DB::transaction(function () use ($v, $wallet, $request, $agent) {
            app(\App\Services\WalletService::class)->credit($wallet, $v['amount']);
            $tx = Transaction::create([
                'reference' => 'FP-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12)),
                'type' => 'float_topup',
                'scope' => 'national',
                'source_rail' => 'treasury',
                'destination_rail' => 'wallet',
                'destination_wallet_id' => $wallet->id,
                'amount' => $v['amount'],
                'currency' => $wallet->currency,
                'status' => 'successful',
                'initiated_by' => $request->user()->id,
                'completed_at' => now(),
                'meta' => array_filter(['channel' => 'float', 'agent_id' => $agent->id, 'note' => $v['note'] ?? null]),
            ]);
            app(\App\Services\LedgerService::class)->recordDoubleEntry($tx, 'flashpay:float', "wallet:{$wallet->id}", $v['amount'], null, 'Approvisionnement float agent');
            return $tx;
        });

        return response()->json(['transaction' => $tx, 'float' => (int) $wallet->fresh()->balance], 201);
    }

    /** Nouveau mot de passe pour un marchand / agent (transmis hors application). */
    public function resetPassword(Request $request, User $user)
    {
        abort_if($user->hasRole('super_admin') && $user->id !== $request->user()->id, 403, 'Mot de passe Super Admin : à changer par son titulaire.');
        $v = $request->validate(['password' => 'required|string|min:4']);
        $user->update(['password' => \Illuminate\Support\Facades\Hash::make($v['password'])]);
        $user->tokens()->delete(); // déconnecte ses appareils

        return response()->json(['message' => 'Mot de passe réinitialisé. L\'utilisateur doit se reconnecter.']);
    }

    /** Utilisateur existant (même numéro) ou nouveau compte + wallet dans la devise du pays. */
    protected function accountFor(array $v, string $role): array
    {
        $corridors = app(\App\Services\Peex\PeexCorridors::class);
        try {
            $route = $corridors->resolve($v['phone'], $v['country'] ?? null);
            $phone = ltrim($route['phone'], '+');
            $country = $route['country'];
            $currency = $route['currency'];
        } catch (\Throwable) {
            $phone = preg_replace('/\D/', '', $v['phone']);
            $country = strtoupper($v['country'] ?? config('flashpay.peex.default_country', 'CG'));
            $currency = $corridors->country($country)['currency'];
        }

        $user = User::whereIn('phone', [$phone, '+' . $phone, $v['phone']])->first();
        $created = false;
        if (! $user) {
            if (empty($v['password'])) {
                abort(response()->json(['message' => 'Mot de passe requis pour un nouveau compte.', 'errors' => ['password' => ['Mot de passe requis.']]], 422));
            }
            $user = User::create([
                'full_name' => $v['full_name'],
                'phone' => $phone,
                'password' => \Illuminate\Support\Facades\Hash::make($v['password']),
            ]);
            $created = true;
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
        if (! $user->wallet) {
            \App\Models\Wallet::create(['user_id' => $user->id, 'balance' => 0, 'currency' => $currency, 'country' => $country]);
        }

        return [$user->fresh(['wallet', 'merchant', 'agent']), $created];
    }

    /** Pastilles du menu (validations en attente). */
    public function badges()
    {
        return response()->json([
            'merchants' => Merchant::where('validation_status', 'pending')->count(),
            'agents' => Agent::where('validation_status', 'pending')->count(),
            'processing' => \App\Models\Transaction::where('status', 'processing')->count(),
            'bank' => \App\Models\Transaction::where('type', 'bank_transfer')->where('status', 'processing')->count(),
            'inactive' => User::where('status', '<>', 'active')->count(),
            'kyc' => User::where('kyc_status', 'submitted')->whereHas('roles', fn ($r) => $r->where('name', 'client'))->count(),
            // Cahier des charges v1.5
            'kyc_docs' => \App\Models\KycDocument::where('status', 'pending')->count(),
            'float' => \App\Models\FloatRequest::where('status', 'pending')->whereNull('super_agent_id')->count(),
            'disputes' => \App\Models\Dispute::whereIn('status', ['open', 'investigating'])->count() + \App\Models\SupportTicket::where('status', 'open')->count(),
            'fraud' => \App\Models\FraudAlert::where('status', 'open')->count(),
            'notifications' => \App\Models\AppNotification::where('audience', 'admin')->whereNull('read_at')->count(),
            'webhooks' => \App\Models\WebhookDelivery::where('status', 'failed')->count(),
            // Dernière alerte : la console émet un son quand une nouvelle notification arrive
            'last_notification' => \App\Models\AppNotification::where('audience', 'admin')->latest('id')->first(['id', 'type', 'title', 'body', 'severity', 'created_at']),
        ]);
    }

    public function pendingMerchants(Request $request)
    {
        return response()->json(Merchant::where('validation_status', 'pending')->with('user')->get());
    }

    public function validateMerchant(Request $request, Merchant $merchant)
    {
        $validated = $request->validate(['decision' => 'required|in:approved,rejected']);

        $merchant->update([
            'validation_status' => $validated['decision'],
            'validated_at' => now(),
            'validated_by' => $request->user()->id,
        ]);

        return response()->json($merchant);
    }

    public function pendingAgents(Request $request)
    {
        return response()->json(Agent::where('validation_status', 'pending')->with('user')->get());
    }

    public function validateAgent(Request $request, Agent $agent)
    {
        $validated = $request->validate(['decision' => 'required|in:approved,rejected']);

        $agent->update([
            'validation_status' => $validated['decision'],
            'validated_at' => now(),
        ]);

        return response()->json($agent);
    }

    public function tariffs()
    {
        return response()->json([
            'operations' => Tariff::OPERATIONS,
            'scopes' => Tariff::SCOPES,
            'tariffs' => Tariff::orderBy('operation_type')->orderBy('scope')->orderBy('min_amount')->get(),
        ]);
    }

    public function upsertTariff(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|exists:tariffs,id',
            'operation_type' => 'required|in:' . implode(',', array_keys(Tariff::OPERATIONS)),
            'scope' => 'required|in:' . implode(',', Tariff::SCOPES),
            'min_amount' => 'required|integer|min:0',
            'max_amount' => 'required|integer|gt:min_amount',
            'fee_type' => 'required|in:fixed,percent',
            'fee_value' => 'required|numeric|min:0',
            'min_fee' => 'nullable|integer|min:0',
            'max_fee' => 'nullable|integer|min:0',
            'active' => 'boolean',
        ]);
        $validated['min_fee'] = $validated['min_fee'] ?? 0;

        $tariff = isset($validated['id'])
            ? tap(Tariff::findOrFail($validated['id']))->update($validated)
            : Tariff::updateOrCreate(
                ['operation_type' => $validated['operation_type'], 'scope' => $validated['scope'], 'min_amount' => $validated['min_amount'], 'max_amount' => $validated['max_amount']],
                $validated,
            );

        return response()->json($tariff, 201);
    }

    public function deleteTariff(Tariff $tariff)
    {
        $tariff->delete();
        return response()->json(['message' => 'Tarif supprimé']);
    }

    public function fxRates()
    {
        return response()->json(\App\Models\ExchangeRate::orderBy('base')->orderBy('quote')->get());
    }

    public function upsertFxRate(Request $request)
    {
        $v = $request->validate([
            'base' => 'required|string|size:3',
            'quote' => 'required|string|size:3|different:base',
            'rate' => 'required|numeric|gt:0',
            'margin_percent' => 'nullable|numeric|min:0|max:20',
            'active' => 'boolean',
            'source' => 'nullable|string|max:50',
        ]);
        $v['base'] = strtoupper($v['base']);
        $v['quote'] = strtoupper($v['quote']);

        $rate = \App\Models\ExchangeRate::updateOrCreate(['base' => $v['base'], 'quote' => $v['quote']], $v + ['source' => 'manuel']);

        return response()->json($rate, 201);
    }

    public function ledgerOverview(Request $request)
    {
        $summary = \App\Models\LedgerEntry::selectRaw('account, type, SUM(amount) as total')
            ->groupBy('account', 'type')
            ->get();

        return response()->json($summary);
    }

    public function activityReport(Request $request)
    {
        $report = Transaction::selectRaw('type, status, COUNT(*) as count, SUM(amount) as volume')
            ->groupBy('type', 'status')
            ->get();

        return response()->json($report);
    }
}
