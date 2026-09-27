<?php

namespace App\Http\Middleware;

use App\Services\Security\PinService;
use Closure;
use Illuminate\Http\Request;

/**
 * Confirmation PIN (§3.3.2, §4.5) des opérations sortantes : le PIN est
 * transmis dans l'en-tête X-FlashPay-Pin (ou le champ "pin").
 * Réponses : 428 pin_required / pin_not_set, 422 pin_invalid, 423 pin_locked.
 */
class RequirePin
{
    public function __construct(protected PinService $pins)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->hasAnyRole(['super_admin', 'support'])) {
            $this->pins->check($user, $request->header('X-FlashPay-Pin') ?? $request->input('pin'));
        }
        return $next($request);
    }
}
