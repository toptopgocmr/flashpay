<?php

namespace App\Http\Middleware;

use App\Services\Security\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle d'habilitation : « cap:send », « cap:cash_in,cash_out »…
 * Refuse (403) si aucun rôle de l'utilisateur ne détient l'une des
 * habilitations demandées (réglage console « Rôles & habilitations »).
 */
class RequireCapability
{
    public function __construct(protected Permissions $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string ...$caps): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }
        foreach ($caps as $cap) {
            if ($this->permissions->allows($user, $cap)) {
                return $next($request);
            }
        }
        $labels = array_map(fn ($c) => $this->permissions->capabilities()[$c]['label'] ?? $c, $caps);

        return response()->json([
            'message' => 'Action non autorisée pour votre profil : ' . implode(' / ', $labels) . '. Contactez FlashPay si vous pensez qu\'il s\'agit d\'une erreur.',
            'error' => 'capability_denied',
            'capabilities' => $caps,
        ], 403);
    }
}
