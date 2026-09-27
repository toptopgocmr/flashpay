<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Clé d'idempotence serveur (§13.1, §4.7.3) : un nouvel essai réseau avec la
 * même clé renvoie la réponse initiale au lieu de débiter une seconde fois.
 * En-tête : Idempotency-Key (8 à 100 caractères).
 */
class Idempotent
{
    public function handle(Request $request, Closure $next, string $required = 'optional')
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            if ($required === 'required') {
                return response()->json(['message' => 'En-tête Idempotency-Key obligatoire.', 'code' => 'idempotency_key_required'], 400);
            }
            return $next($request);
        }
        if (strlen($key) < 8 || strlen($key) > 100) {
            return response()->json(['message' => 'Idempotency-Key invalide (8 à 100 caractères).', 'code' => 'idempotency_key_invalid'], 400);
        }

        $scope = $request->attributes->get('merchant_api_key')
            ? 'key:' . $request->attributes->get('merchant_api_key')->id
            : 'user:' . ($request->user()?->id ?? $request->ip());
        $hash = hash('sha256', $request->method() . '|' . $request->path() . '|' . json_encode($request->except(['pin'])));

        $existing = IdempotencyKey::where('scope', $scope)->where('key', $key)->first();
        if ($existing) {
            if ($existing->request_hash !== $hash) {
                return response()->json(['message' => 'Cette clé d\'idempotence a déjà été utilisée pour une autre requête.', 'code' => 'idempotency_key_reused'], 422);
            }
            if ($existing->response_code === null) {
                return response()->json(['message' => 'Requête identique en cours de traitement.', 'code' => 'idempotency_in_progress'], 409);
            }
            return response($existing->response_body, $existing->response_code)
                ->header('Content-Type', 'application/json')->header('Idempotent-Replayed', 'true');
        }

        try {
            $record = IdempotencyKey::create(['key' => $key, 'scope' => $scope, 'route' => mb_substr($request->path(), 0, 190), 'request_hash' => $hash]);
        } catch (QueryException) {
            return response()->json(['message' => 'Requête identique en cours de traitement.', 'code' => 'idempotency_in_progress'], 409);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $record->update(['response_code' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        } else {
            $record->delete(); // erreur serveur : le client peut réessayer
        }

        return $response;
    }
}
