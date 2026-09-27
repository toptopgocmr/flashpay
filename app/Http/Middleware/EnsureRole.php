<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Vérifie que l'utilisateur authentifié possède l'un des rôles requis.
 * Usage dans les routes : ->middleware('role:merchant,super_admin')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole($roles)) {
            return response()->json(['message' => 'Accès refusé pour ce profil.'], 403);
        }

        return $next($request);
    }
}
