<?php

namespace App\Http\Middleware;

use App\Models\MerchantApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Authentification serveur à serveur de l'API e-commerce (§4.7.1, §4.7.3) :
 * Authorization: Bearer sk_live_… | sk_sandbox_…, restriction IP optionnelle,
 * limitation de débit par clé (120 requêtes / minute).
 */
class AuthenticateMerchantApi
{
    public function handle(Request $request, Closure $next)
    {
        $secret = $request->bearerToken();
        $key = $secret ? MerchantApiKey::with('merchant.user.wallet')->where('secret_hash', hash('sha256', $secret))->where('active', true)->first() : null;

        if (! $key) {
            return $this->error('Clé API invalide ou révoquée.', 'invalid_api_key', 401);
        }
        if ($key->allowed_ips && ! in_array($request->ip(), $key->allowed_ips, true)) {
            return $this->error('Adresse IP non autorisée pour cette clé.', 'ip_not_allowed', 403);
        }
        if (! $key->merchant->online_payments || $key->merchant->user?->status !== 'active') {
            return $this->error('Paiement en ligne désactivé pour ce marchand.', 'online_payments_disabled', 403);
        }
        if ($key->environment === 'live' && ! app(\App\Services\Ops\PlatformSettings::class)->channelEnabled('ecommerce')) {
            return $this->error('API e-commerce temporairement indisponible.', 'channel_unavailable', 503);
        }

        $limiterKey = 'merchant-api:' . $key->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 120)) {
            return $this->error('Trop de requêtes. Réessayez dans ' . RateLimiter::availableIn($limiterKey) . ' s.', 'rate_limited', 429);
        }
        RateLimiter::hit($limiterKey, 60);

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('merchant_api_key', $key);

        return $next($request);
    }

    protected function error(string $message, string $code, int $status)
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
