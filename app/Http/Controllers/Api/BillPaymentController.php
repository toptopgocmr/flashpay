<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Bills\BillPaymentService;
use Illuminate\Http\Request;

/** App : crédit téléphonique, abonnement TV et factures, payés depuis le wallet (MaishaPay). */
class BillPaymentController extends Controller
{
    public function __construct(protected BillPaymentService $bills)
    {
    }

    public function catalog(Request $request)
    {
        return response()->json($this->bills->catalog($request->user()));
    }

    public function quote(Request $request)
    {
        $v = $request->validate([
            'service' => 'required|in:' . implode(',', array_keys(BillPaymentService::SERVICES)),
            'amount' => 'required|integer|min:1|max:5000000',
        ]);
        return response()->json($this->bills->quote($request->user(), $v['service'], (int) $v['amount']));
    }

    public function pay(Request $request, string $service)
    {
        abort_unless(isset(BillPaymentService::SERVICES[$service]), 404);
        $v = $request->validate([
            'amount' => 'required|integer|min:1|max:5000000',
            'provider' => 'required|string|max:40',
            'phone' => 'nullable|string|max:20',
            'decoder_number' => 'nullable|string|max:40',
            'package' => 'nullable|string|max:60',
            'duration_months' => 'nullable|integer|min:1|max:24',
            'account_number' => 'nullable|string|max:60',
        ]);
        $tx = $this->bills->pay($request->user(), $service, (int) $v['amount'], $v);
        $code = match ($tx->status) { 'successful' => 201, 'processing' => 202, default => 422 };
        return response()->json(app(PaymentController::class)->txPayload($tx), $code);
    }
}
