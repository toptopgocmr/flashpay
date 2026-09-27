<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Super Admin : gestion de TOUS les comptes (clients, marchands, agents,
 * support, super admins) — activation / désactivation, à l'unité ou en masse.
 */
class AccountController extends Controller
{
    /** ?role=client|merchant|agent|support|super_admin  ?status=active|inactive  ?q=nom/téléphone/email */
    public function index(Request $request)
    {
        $page = $this->filtered($request)
            ->with(['roles:id,name', 'wallet:id,user_id,balance,currency', 'merchant:id,user_id,business_name'])
            ->latest()
            ->paginate(min(max((int) $request->query('per_page', 25), 10), 100))
            ->withQueryString();

        $page->getCollection()->transform(fn (User $u) => [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'phone' => $u->phone,
            'email' => $u->email,
            'roles' => $u->roles->pluck('name'),
            'business_name' => $u->merchant?->business_name,
            'balance' => $u->wallet?->balance,
            'currency' => $u->wallet?->currency,
            'status' => $u->status,
            'active' => $u->status === 'active',
            'status_reason' => $u->status_reason,
            'status_changed_at' => $u->status_changed_at,
            'created_at' => $u->created_at,
            'is_me' => $u->id === $request->user()->id,
        ]);

        return response()->json($page->toArray() + [
            'counts' => [
                'total' => User::count(),
                'active' => User::where('status', 'active')->count(),
                'inactive' => User::where('status', '<>', 'active')->count(),
            ],
        ]);
    }

    /** Active ou désactive un compte : { active: bool, reason?: string } */
    public function setStatus(Request $request, User $user)
    {
        $v = $request->validate(['active' => 'required|boolean', 'reason' => 'nullable|string|max:200']);

        if (! $v['active']) {
            if ($user->id === $request->user()->id) {
                return response()->json(['message' => 'Vous ne pouvez pas désactiver votre propre compte.'], 422);
            }
            if ($user->hasRole('super_admin') && $this->activeAdmins()->where('users.id', '<>', $user->id)->doesntExist()) {
                return response()->json(['message' => 'Impossible de désactiver le dernier Super Admin actif.'], 422);
            }
        }

        $this->apply(User::whereKey($user->id), $v['active'], $v['reason'] ?? null, $request->user()->id);

        return response()->json(['message' => $v['active'] ? 'Compte activé.' : 'Compte désactivé.', 'status' => $user->fresh()->status]);
    }

    /**
     * Activation / désactivation en masse :
     *   { active: bool, ids: [1,2,3] }                    -> comptes sélectionnés
     *   { active: bool, all: true, role?, status?, q? }   -> tous les comptes (du filtre courant)
     * Votre propre compte est toujours exclu ; les Super Admins ne sont désactivés
     * en masse que s'ils sont explicitement sélectionnés.
     */
    public function bulkStatus(Request $request)
    {
        $v = $request->validate([
            'active' => 'required|boolean',
            'ids' => 'array|required_without:all',
            'ids.*' => 'integer',
            'all' => 'boolean',
            'reason' => 'nullable|string|max:200',
        ]);

        $q = ! empty($v['all'])
            ? $this->filtered($request)
            : User::whereIn('id', $v['ids'] ?? []);

        $q->where('users.id', '<>', $request->user()->id);

        if (! $v['active'] && ! empty($v['all'])) {
            $q->whereDoesntHave('roles', fn ($r) => $r->where('name', 'super_admin'));
        }

        $count = $this->apply($q, $v['active'], $v['reason'] ?? null, $request->user()->id);

        return response()->json([
            'message' => $count . ' compte(s) ' . ($v['active'] ? 'activé(s)' : 'désactivé(s)') . '.',
            'affected' => $count,
        ]);
    }

    private function apply(Builder $q, bool $active, ?string $reason, int $by): int
    {
        $target = $active ? 'active' : 'suspended';
        $ids = (clone $q)->where('status', '<>', $target)->pluck('users.id');
        if ($ids->isEmpty()) {
            return 0;
        }

        User::whereIn('id', $ids)->update([
            'status' => $target,
            'status_reason' => $active ? null : $reason,
            'status_changed_at' => now(),
            'status_changed_by' => $by,
        ]);

        if (! $active) {
            // Déconnexion immédiate : révoque tous les jetons des comptes désactivés
            \Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $ids)->delete();
        }

        return $ids->count();
    }

    private function filtered(Request $request): Builder
    {
        $q = User::query();

        if ($role = $request->input('role')) {
            $q->whereHas('roles', fn ($r) => $r->where('name', $role));
        }
        if ($status = $request->input('status')) {
            $status === 'active' ? $q->where('status', 'active') : $q->where('status', '<>', 'active');
        }
        if ($term = trim((string) $request->input('q'))) {
            $q->where(fn ($w) => $w->where('full_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"));
        }

        return $q;
    }

    private function activeAdmins(): Builder
    {
        return User::where('status', 'active')->whereHas('roles', fn ($r) => $r->where('name', 'super_admin'));
    }
}
