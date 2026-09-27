<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Bloque toute requête d'un compte désactivé par le Super Admin,
 * même si un jeton Sanctum est encore valide.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            return response()->json([
                'message' => 'Compte désactivé. Contactez le support FlashPay.',
                'code' => 'account_disabled',
            ], 403);
        }

        return $next($request);
    }
}
