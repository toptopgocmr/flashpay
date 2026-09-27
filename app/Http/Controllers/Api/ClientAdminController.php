<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Support\UserActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Super Admin — gestion des clients : liste, fiche, création, modification,
 * KYC, gel du wallet (activation / désactivation via AccountController).
 */
class ClientAdminController extends Controller
{
    /**
     * ?q= ?status=active|inactive ?kyc=pending|submitted|verified|rejected ?wallet=frozen
     * ?country= ?from= ?to= (date d'inscription) ?sort=recent|balance|activity|name
     */
    public function index(Request $request)
    {
        $q = $this->base()->with(['wallet:id,user_id,balance,currency,country,status', 'roles:id,name']);

        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('users.full_name', 'like', "%{$term}%")->orWhere('users.phone', 'like', "%{$term}%")->orWhere('users.email', 'like', "%{$term}%"));
        }
        if ($s = $request->query('status')) {
            $s === 'active' ? $q->where('users.status', 'active') : $q->where('users.status', '<>', 'active');
        }
        if ($k = $request->query('kyc')) {
            $q->whereIn('users.kyc_status', $k === 'pending' ? ['pending', 'submitted'] : [$k]);
        }
        if ($request->query('wallet') === 'frozen') {
            $q->whereHas('wallet', fn ($w) => $w->where('status', 'frozen'));
        }
        if ($c = $request->query('country')) {
            $q->whereHas('wallet', fn ($w) => $w->where('country', strtoupper($c)));
        }
        if ($from = $this->date($request->query('from'))) {
            $q->where('users.created_at', '>=', $from);
        }
        if ($to = $this->date($request->query('to'))) {
            $q->where('users.created_at', '<', $to->addDay());
        }

        // Nombre de transactions et dernière activité (wallet source / destination)
        $txCount = Transaction::selectRaw('COUNT(*)')->whereColumn('transactions.source_wallet_id', 'wallets.id')->orWhereColumn('transactions.destination_wallet_id', 'wallets.id');
        $lastTx = Transaction::selectRaw('MAX(transactions.created_at)')->whereColumn('transactions.source_wallet_id', 'wallets.id')->orWhereColumn('transactions.destination_wallet_id', 'wallets.id');
        $q->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->select('users.*')
            ->selectSub($txCount, 'tx_count')
            ->selectSub($lastTx, 'last_activity');

        match ($request->query('sort')) {
            'balance' => $q->orderByDesc('wallets.balance'),
            'activity' => $q->orderByDesc('last_activity'),
            'name' => $q->orderBy('full_name'),
            default => $q->orderByDesc('users.created_at'),
        };

        $page = $q->paginate(min(max((int) $request->query('per_page', 25), 10), 100))->withQueryString();
        $page->getCollection()->transform(fn (User $u) => $this->row($u, $request));

        $base = fn () => $this->base();
        return response()->json($page->toArray() + [
            'counts' => [
                'total' => $base()->count(),
                'active' => $base()->where('status', 'active')->count(),
                'inactive' => $base()->where('status', '<>', 'active')->count(),
                'kyc_pending' => $base()->whereIn('kyc_status', ['pending', 'submitted'])->count(),
                'kyc_verified' => $base()->where('kyc_status', 'verified')->count(),
                'frozen' => $base()->whereHas('wallet', fn ($w) => $w->where('status', 'frozen'))->count(),
                'new_30d' => $base()->where('created_at', '>=', now()->subDays(30))->count(),
                'balance' => (int) Wallet::whereIn('user_id', $base()->select('users.id'))->sum('balance'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'full_name' => 'required|string|max:150',
            'phone' => 'required|string|max:25',
            'email' => 'nullable|email|max:150|unique:users,email',
            'password' => 'required|string|min:4',
            'country' => 'nullable|string|size:2',
        ]);

        [$phone, $country, $currency] = $this->normalizePhone($v['phone'], $v['country'] ?? null);
        if (User::whereIn('phone', [$phone, '+' . $phone, $v['phone']])->exists()) {
            return response()->json(['message' => 'Ce numéro a déjà un compte FlashPay.', 'errors' => ['phone' => ['Numéro déjà utilisé.']]], 422);
        }

        $user = User::create(['full_name' => $v['full_name'], 'phone' => $phone, 'email' => $v['email'] ?? null, 'password' => Hash::make($v['password'])]);
        $user->assignRole('client');
        Wallet::create(['user_id' => $user->id, 'balance' => 0, 'currency' => $currency, 'country' => $country]);

        return response()->json([
            'client' => $this->row($user->fresh(['wallet', 'roles']), $request),
            'login' => ['phone' => $user->phone, 'profile' => 'Client', 'new_account' => true],
            'message' => 'Compte client créé.',
        ], 201);
    }

    /** Fiche complète : profil, wallet, statistiques, dernières transactions. */
    public function show(Request $request, User $user)
    {
        $this->ensureClient($user);
        $user->load(['wallet', 'roles:id,name']);

        return response()->json([
            'client' => $this->row($user, $request) + [
                'phone_verified_at' => $user->phone_verified_at,
                'status_changed_by' => $user->status_changed_by ? User::whereKey($user->status_changed_by)->value('full_name') : null,
                'other_profiles' => $user->roles->pluck('name')->reject(fn ($r) => $r === 'client')->values(),
            ],
            'stats' => UserActivity::stats($user),
            'recent' => UserActivity::recent($user),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->ensureClient($user);
        $v = $request->validate([
            'full_name' => 'sometimes|required|string|max:150',
            'phone' => ['sometimes', 'required', 'string', 'max:25'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        if (isset($v['phone'])) {
            [$phone] = $this->normalizePhone($v['phone'], $user->wallet?->country);
            if (User::where('id', '<>', $user->id)->whereIn('phone', [$phone, '+' . $phone])->exists()) {
                return response()->json(['message' => 'Ce numéro est déjà utilisé par un autre compte.', 'errors' => ['phone' => ['Numéro déjà utilisé.']]], 422);
            }
            $v['phone'] = $phone;
        }
        $user->update($v);

        return response()->json(['message' => 'Client mis à jour.', 'client' => $this->row($user->fresh(['wallet', 'roles']), $request)]);
    }

    /** Décision KYC : { decision: verified|rejected|pending } */
    public function kyc(Request $request, User $user)
    {
        $this->ensureClient($user);
        $v = $request->validate(['decision' => 'required|in:verified,rejected,pending', 'tier' => 'nullable|integer|min:0|max:2']);
        $before = (int) $user->kyc_tier;
        $tier = $v['tier'] ?? match ($v['decision']) { 'verified' => 2, 'rejected' => 0, default => $before };
        $user->update(['kyc_status' => $v['decision'], 'kyc_tier' => $tier]);
        \App\Support\Audit::log('kyc.decision', $user, ['decision' => $v['decision'], 'tier' => $tier, 'previous_tier' => $before]);
        app(\App\Services\Compliance\KycService::class)->notifyTierChange($user, $before, $tier);

        return response()->json(['message' => ['verified' => 'KYC validé.', 'rejected' => 'KYC rejeté.', 'pending' => 'KYC remis en attente.'][$v['decision']], 'kyc_status' => $v['decision']]);
    }

    /** Gel / dégel du wallet (clients et agents) : { frozen: bool }. Un wallet gelé ne peut plus être débité. */
    public function walletStatus(Request $request, User $user)
    {
        $v = $request->validate(['frozen' => 'required|boolean']);
        $wallet = $user->wallet ?? abort(422, 'Aucun wallet pour ce compte.');
        $wallet->update(['status' => $v['frozen'] ? 'frozen' : 'active']);
        \App\Support\Audit::log($v['frozen'] ? 'wallet.freeze' : 'wallet.unfreeze', $wallet);

        return response()->json(['message' => $v['frozen'] ? 'Wallet gelé : plus aucun débit possible.' : 'Wallet réactivé.', 'wallet_status' => $wallet->status]);
    }

    // ------------------------------------------------------------------

    private function base()
    {
        return User::query()->whereHas('roles', fn ($r) => $r->where('name', 'client'));
    }

    private function ensureClient(User $user): void
    {
        abort_unless($user->hasRole('client'), 404, 'Client introuvable.');
    }

    private function row(User $u, Request $request): array
    {
        return [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'phone' => $u->phone,
            'email' => $u->email,
            'date_of_birth' => optional($u->date_of_birth)->format('Y-m-d'),
            'place_of_birth' => $u->place_of_birth,
            'country' => $u->wallet?->country,
            'balance' => (int) ($u->wallet?->balance ?? 0),
            'currency' => $u->wallet?->currency ?? 'XAF',
            'wallet_status' => $u->wallet?->status ?? 'active',
            'kyc_status' => $u->kyc_status,
            'status' => $u->status,
            'active' => $u->status === 'active',
            'status_reason' => $u->status_reason,
            'status_changed_at' => $u->status_changed_at,
            'roles' => $u->relationLoaded('roles') ? $u->roles->pluck('name') : [],
            'tx_count' => (int) ($u->tx_count ?? 0),
            'last_activity' => $u->last_activity ?? null,
            'created_at' => $u->created_at,
        ];
    }

    private function date($v): ?CarbonImmutable
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::createFromFormat('Y-m-d', $v)->startOfDay() : null;
    }

    /** @return array{0:string,1:string,2:string} téléphone normalisé, pays, devise */
    private function normalizePhone(string $raw, ?string $country): array
    {
        $corridors = app(\App\Services\Peex\PeexCorridors::class);
        try {
            $route = $corridors->resolve($raw, $country);
            return [ltrim($route['phone'], '+'), $route['country'], $route['currency']];
        } catch (\Throwable) {
            $c = strtoupper($country ?: config('flashpay.peex.default_country', 'CG'));
            return [preg_replace('/\D/', '', $raw), $c, $corridors->country($c)['currency'] ?? 'XAF'];
        }
    }
}
