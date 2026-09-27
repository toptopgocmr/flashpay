<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Services\Ecommerce\PaymentIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * API REST e-commerce v1 (§4.7.2) — authentification par clé secrète.
 *   POST /api/v1/payment-intents                créer (Idempotency-Key obligatoire)
 *   GET  /api/v1/payment-intents/{id}           consulter le statut
 *   POST /api/v1/payment-intents/{id}/confirm   déclencher la confirmation client
 *   POST /api/v1/payment-intents/{id}/cancel    annuler un paiement non confirmé
 *   POST /api/v1/refunds                        rembourser (total / partiel)
 *   GET  /api/v1/refunds/{id}                   consulter un remboursement
 */
class PaymentIntentApiController extends Controller
{
    public function __construct(protected PaymentIntentService $intents)
    {
    }

    protected function key(Request $r)
    {
        return $r->attributes->get('merchant_api_key');
    }

    protected function intent(Request $r, string $id): PaymentIntent
    {
        $pi = PaymentIntent::where('public_id', $id)->where('merchant_id', $this->key($r)->merchant_id)->first();
        if (! $pi || $pi->environment !== $this->key($r)->environment) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Paiement introuvable.']], 404));
        }
        return $pi;
    }

    protected function validated(Request $r, array $rules): array
    {
        $v = Validator::make($r->all(), $rules);
        if ($v->fails()) {
            abort(response()->json(['error' => ['code' => 'invalid_request', 'message' => $v->errors()->first(), 'fields' => $v->errors()]], 422));
        }
        return $v->validated();
    }

    protected function wrap(callable $fn)
    {
        try {
            return $fn();
        } catch (BusinessException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status);
        }
    }

    public function create(Request $request)
    {
        $v = $this->validated($request, [
            'amount' => 'required|integer|min:100',
            'currency' => 'nullable|string|size:3',
            'order_reference' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:190',
            'customer_phone' => 'nullable|string|max:25',
            'return_url' => 'nullable|url|max:255',
            'cancel_url' => 'nullable|url|max:255',
            'expires_in_minutes' => 'nullable|integer|min:2|max:1440',
            'metadata' => 'nullable|array|max:20',
            'channel' => 'nullable|in:ecommerce,mini_program',
        ]);
        return $this->wrap(fn () => response()->json($this->intents->present($this->intents->create($this->key($request), $v, $v['channel'] ?? 'ecommerce')), 201));
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->intents->present($this->intent($request, $id)));
    }

    public function confirm(Request $request, string $id)
    {
        $v = $this->validated($request, ['customer_phone' => 'nullable|string|max:25', 'test_outcome' => 'nullable|in:succeeded,failed']);
        return $this->wrap(fn () => response()->json($this->intents->confirm($this->intent($request, $id), $v)));
    }

    public function cancel(Request $request, string $id)
    {
        $pi = $this->intent($request, $id);
        if ($pi->status !== 'requires_confirmation') {
            return response()->json(['error' => ['code' => 'intent_not_cancelable', 'message' => 'Ce paiement ne peut plus être annulé.']], 422);
        }
        $pi->update(['status' => 'canceled']);
        return response()->json($this->intents->present($pi->fresh()));
    }

    public function refund(Request $request)
    {
        $v = $this->validated($request, ['payment_intent' => 'required|string', 'amount' => 'nullable|integer|min:1', 'reason' => 'nullable|string|max:190']);
        $pi = $this->intent($request, $v['payment_intent']);
        return $this->wrap(fn () => response()->json($this->intents->refund($pi, $v['amount'] ?? null, $v['reason'] ?? null), 201));
    }

    public function showRefund(Request $request, string $id)
    {
        $r = Refund::with('transaction')->where('public_id', $id)->where('merchant_id', $this->key($request)->merchant_id)->firstOrFail();
        return response()->json(['id' => $r->public_id, 'object' => 'refund', 'amount' => $r->amount, 'currency' => $r->currency, 'reason' => $r->reason, 'status' => $r->status,
            'payment_intent' => $r->payment_intent_id ? PaymentIntent::find($r->payment_intent_id)?->public_id : null, 'created' => $r->created_at->timestamp]);
    }
}
