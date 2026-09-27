<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\MerchantCashier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Merchant\CashierService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Console — rôles & habilitations (maquettes v2) et supervision des caissiers.
 */
class RolesAdminController extends Controller
{
    /** Matrice des habilitations + effectifs par rôle + comptes démo (hors production). */
    public function index()
    {
        $count = fn (string $role) => User::whereHas('roles', fn ($r) => $r->where('name', $role))->count();
        $active = fn (string $role) => User::where('status', 'active')->whereHas('roles', fn ($r) => $r->where('name', $role))->count();

        $approved = fn () => Agent::where('validation_status', 'approved');

        $counts = [
            'client' => ['total' => $count('client'), 'active' => $active('client')],
            'merchant' => ['total' => $count('merchant'), 'active' => $active('merchant')],
            'cashier' => ['total' => MerchantCashier::count(), 'active' => MerchantCashier::where('status', 'active')->count()],
            'agent' => [
                'total' => Agent::where('is_super_agent', false)->whereNull('parent_agent_id')->count(),
                'active' => $approved()->where('is_super_agent', false)->whereNull('parent_agent_id')->count(),
            ],
            'sub_agent' => ['total' => Agent::whereNotNull('parent_agent_id')->count(), 'active' => $approved()->whereNotNull('parent_agent_id')->count()],
            'super_agent' => ['total' => Agent::where('is_super_agent', true)->count(), 'active' => $approved()->where('is_super_agent', true)->count()],
            'support' => ['total' => $count('support'), 'active' => $active('support')],
            'super_admin' => ['total' => $count('super_admin'), 'active' => $active('super_admin')],
        ];

        $demo = [];
        if (! app()->environment('production') && class_exists(\Database\Seeders\DemoAccountsSeeder::class)) {
            $phones = ['client' => '242061234567', 'merchant' => '242062345678', 'super_agent' => '242063456789', 'sub_agent' => '242064567890', 'cashier' => '242065678901'];
            foreach ($phones as $role => $phone) {
                if ($u = User::where('phone', $phone)->first(['id', 'full_name', 'phone'])) {
                    $demo[] = ['role' => $role, 'name' => $u->full_name, 'phone' => $u->phone, 'code' => \Database\Seeders\DemoAccountsSeeder::CODE];
                }
            }
        }

        return response()->json([
            'capabilities' => config('roles.capabilities'),
            'roles' => config('roles.roles'),
            'counts' => $counts,
            'demo_accounts' => $demo,
        ]);
    }

    /** Tous les caissiers, tous marchands confondus : ?q=&status=active|revoked */
    public function cashiers(Request $request)
    {
        $q = MerchantCashier::with(['user:id,full_name,phone,status', 'merchant:id,business_name', 'outlet:id,name'])->latest();
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->whereHas('user', fn ($u) => $u->where('full_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('merchant', fn ($m) => $m->where('business_name', 'like', "%{$term}%")));
        }

        return response()->json([
            'data' => $q->limit(300)->get()->map(fn (MerchantCashier $c) => [
                'id' => $c->id,
                'name' => $c->user?->full_name,
                'phone' => $c->user?->phone,
                'merchant_id' => $c->merchant_id,
                'merchant' => $c->merchant?->business_name,
                'outlet' => $c->outlet?->name,
                'status' => $c->status,
                'revoked_reason' => $c->revoked_reason,
                'today_collected' => (int) Transaction::where('meta->cashier_id', $c->id)->where('status', 'successful')->whereDate('created_at', today())->sum('amount'),
                'total_collected' => (int) Transaction::where('meta->cashier_id', $c->id)->where('status', 'successful')->sum('amount'),
                'last_login' => $c->user ? $c->user->devices()->max('last_seen_at') : null,
                'created_at' => $c->created_at,
            ]),
            'counts' => MerchantCashier::selectRaw('status as s, COUNT(*) as c')->groupBy('s')->pluck('c', 's'),
        ]);
    }

    /** Révoquer / réactiver un caissier depuis la console. */
    public function cashierStatus(Request $request, MerchantCashier $cashier, CashierService $service)
    {
        $v = $request->validate(['action' => 'required|in:revoke,reactivate', 'reason' => 'nullable|string|max:150']);
        $c = $v['action'] === 'revoke'
            ? $service->revoke($cashier, $v['reason'] ?? 'Révoqué par FlashPay')
            : $service->reactivate($cashier);
        Audit::log('admin.cashier.' . $v['action'], $cashier, ['reason' => $v['reason'] ?? null]);

        return response()->json(['status' => $c->status, 'message' => $v['action'] === 'revoke' ? 'Accès caissier révoqué.' : 'Accès caissier réactivé.']);
    }
}
