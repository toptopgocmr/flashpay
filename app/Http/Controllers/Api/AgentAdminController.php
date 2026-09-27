<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Super Admin — fiche agent (float, performance, transactions) et modification.
 * Liste / création / validation / approvisionnement : AdminController.
 */
class AgentAdminController extends Controller
{
    /** ?from=&to= : période des statistiques (défaut : 30 derniers jours). */
    public function show(Request $request, Agent $agent)
    {
        $user = $agent->user()->with(['wallet', 'roles:id,name'])->firstOrFail();
        $wid = $user->wallet?->id ?? 0;

        $to = $this->date($request->query('to')) ?? CarbonImmutable::today();
        $from = $this->date($request->query('from')) ?? $to->subDays(29);
        $end = $to->addDay();

        $ops = Transaction::where('created_at', '>=', $from)->where('created_at', '<', $end)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('type', 'cash_in')->where('source_wallet_id', $wid))
                ->orWhere(fn ($x) => $x->whereIn('type', ['cash_out', 'cash_pickup', 'float_topup'])->where('destination_wallet_id', $wid)));

        $sum = fn ($type) => (clone $ops)->where('type', $type)->selectRaw("COUNT(*) as c, COALESCE(SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END),0) as v")->first();
        $dep = $sum('cash_in');
        $out = $sum('cash_out');
        $qr = $sum('cash_pickup');
        $float = $sum('float_topup');

        $commission = (clone $ops)->where('type', 'cash_pickup')->where('status', 'successful')->get(['meta'])
            ->sum(fn ($t) => (int) ($t->meta['agent_commission'] ?? 0));
        $clients = (clone $ops)->whereIn('type', ['cash_in', 'cash_out', 'cash_pickup'])
            ->selectRaw("COUNT(DISTINCT CASE WHEN type = 'cash_in' THEN destination_wallet_id ELSE source_wallet_id END) as n")->value('n');

        return response()->json([
            'agent' => [
                'id' => $agent->id,
                'user_id' => $user->id,
                'name' => $user->full_name,
                'phone' => $user->phone,
                'email' => $user->email,
                'zone' => $agent->zone,
                'country' => $agent->country ?? $user->wallet?->country,
                'city' => $agent->city,
                'validation_status' => $agent->validation_status,
                'validated_at' => $agent->validated_at,
                'account_status' => $user->status,
                'active' => $user->status === 'active',
                'status_reason' => $user->status_reason,
                'status_changed_at' => $user->status_changed_at,
                'float' => (int) ($user->wallet?->balance ?? 0),
                'currency' => $user->wallet?->currency ?? 'XAF',
                'wallet_status' => $user->wallet?->status ?? 'active',
                'created_at' => $agent->created_at,
                'agent_code' => $agent->agent_code,
                'pos_code' => $agent->pos_code,
                'is_super_agent' => (bool) $agent->is_super_agent,
                'parent_agent_id' => $agent->parent_agent_id,
                'parent_name' => $agent->parent?->user?->full_name,
                'sub_agents' => $agent->subAgents()->count(),
                'low_float_threshold' => (int) $agent->low_float_threshold,
                'pending_float_requests' => $agent->floatRequests()->where('status', 'pending')->count(),
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'performance' => [
                'deposits' => ['count' => (int) $dep->c, 'volume' => (int) $dep->v],
                'withdrawals_wallet' => ['count' => (int) $out->c, 'volume' => (int) $out->v],
                'withdrawals_qr' => ['count' => (int) $qr->c, 'volume' => (int) $qr->v],
                'float_topups' => ['count' => (int) $float->c, 'volume' => (int) $float->v],
                'commission' => $commission,
                'clients_served' => (int) $clients,
                'operations' => (int) $dep->c + (int) $out->c + (int) $qr->c,
                'volume' => (int) $dep->v + (int) $out->v + (int) $qr->v,
            ],
            'stats' => UserActivity::stats($user),
            'recent' => UserActivity::recent($user, 20),
        ]);
    }

    public function update(Request $request, Agent $agent)
    {
        $user = $agent->user;
        $v = $request->validate([
            'full_name' => 'sometimes|required|string|max:150',
            'phone' => 'sometimes|required|string|max:25',
            'email' => 'sometimes|nullable|email|max:150|unique:users,email,' . $user->id,
            'zone' => 'sometimes|nullable|string|max:120',
            'country' => 'sometimes|required|string|size:2',
            'city' => 'sometimes|required|string|max:80',
        ]);

        if (isset($v['phone'])) {
            $phone = preg_replace('/\D/', '', $v['phone']);
            try {
                $phone = ltrim(app(\App\Services\Peex\PeexCorridors::class)->resolve($v['phone'], $v['country'] ?? $agent->country)['phone'], '+');
            } catch (\Throwable) {
            }
            if (User::where('id', '<>', $user->id)->whereIn('phone', [$phone, '+' . $phone])->exists()) {
                return response()->json(['message' => 'Ce numéro est déjà utilisé par un autre compte.', 'errors' => ['phone' => ['Numéro déjà utilisé.']]], 422);
            }
            $v['phone'] = $phone;
        }

        $user->update(array_intersect_key($v, array_flip(['full_name', 'phone', 'email'])));
        $agentData = array_intersect_key($v, array_flip(['zone', 'country', 'city']));
        if (isset($agentData['country'])) {
            $agentData['country'] = strtoupper($agentData['country']);
        }
        $agent->update($agentData);

        return response()->json(['message' => 'Agent mis à jour.']);
    }

    private function date($v): ?CarbonImmutable
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::createFromFormat('Y-m-d', $v)->startOfDay() : null;
    }
}
