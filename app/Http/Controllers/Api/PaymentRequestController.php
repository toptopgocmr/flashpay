<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Services\Ecommerce\PaymentIntentService;
use App\Services\Merchant\PaymentRequestService;
use Illuminate\Http\Request;

/**
 * Côté payeur : QR dynamique / lien de paiement / session NFC (§3.3.4, §6.2, §6.3)
 * et paiements e-commerce à confirmer dans l'app (§4.7.4). PIN exigé au paiement.
 */
class PaymentRequestController extends Controller
{
    public function __construct(protected PaymentRequestService $requests, protected PaymentIntentService $intents)
    {
    }

    public function show(Request $request, string $token)
    {
        return response()->json($this->requests->present($this->requests->find($token, $request->query('s'))));
    }

    public function pay(Request $request, string $token)
    {
        $v = $request->validate([
            's' => 'nullable|string|max:32',
            'source' => 'nullable|in:wallet,mobile',
            'source_phone' => 'required_if:source,mobile|nullable|string|max:25',
        ]);
        $tx = $this->requests->pay($request->user(), $token, $v['s'] ?? null, $v['source'] ?? 'wallet', $v['source_phone'] ?? null);
        return response()->json($tx, match ($tx->status) { 'successful' => 201, 'processing' => 202, default => 422 });
    }

    // ------------------------------------------------ E-commerce dans l'app

    public function pendingIntents(Request $request)
    {
        $u = $request->user();
        $phones = [$u->phone, '+' . ltrim($u->phone, '+'), ltrim($u->phone, '+')];
        return response()->json(PaymentIntent::with('merchant:id,business_name')->whereIn('customer_phone', $phones)
            ->where('status', 'requires_confirmation')->where('expires_at', '>', now())->latest()->get()
            ->map(fn ($pi) => $this->intents->present($pi)));
    }

    public function showIntent(string $publicId)
    {
        return response()->json($this->intents->present(PaymentIntent::where('public_id', $publicId)->firstOrFail()));
    }

    public function payIntent(Request $request, string $publicId)
    {
        $pi = PaymentIntent::where('public_id', $publicId)->firstOrFail();
        $pi = $this->intents->payByUser($request->user(), $pi);
        return response()->json($this->intents->present($pi), $pi->status === 'succeeded' ? 201 : ($pi->status === 'processing' ? 202 : 422));
    }
}
