<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CashNetworkException;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\WithdrawalVoucher;
use App\Services\CashNetworkService;
use App\Services\Payments\PaymentMethodsService;
use Illuminate\Http\Request;

/**
 * Moyens par pays, code de paiement QR (style Alipay), bons de retrait
 * (cash pickup / GAB), encaissement marchand par code, dépôt agent par QR.
 *
 * Client
 *   GET  /api/pay/methods?country=CG&operation=deposit|withdraw|pay
 *   POST /api/pay/code                          code de paiement 2 min (QR)
 *   GET  /api/pay/vouchers                      mes bons de retrait
 *   POST /api/pay/vouchers                      créer un bon {channel: cash_pickup|atm, amount, country}
 *   POST /api/pay/vouchers/{voucher}/cancel     annuler (remboursement)
 * Marchand
 *   POST /api/merchant/charge-code              {code, amount} : encaisser le code du client
 * Agent
 *   POST /api/agent/cash-in                     {client_code | client_phone, amount}
 *   GET  /api/agent/vouchers/{code}             vérifier un bon avant de payer
 *   POST /api/agent/vouchers/redeem             {code} : espèces remises
 * Banque partenaire (GAB) — header X-Partner-Key
 *   POST /api/partners/atm/verify               {code}
 *   POST /api/partners/atm/redeem               {code, atm_ref}
 */
class CashController extends Controller
{
    public function __construct(
        protected CashNetworkService $cash,
        protected PaymentMethodsService $methods,
    ) {
    }

    // ------------------------------------------------------------ Client

    public function methods(Request $request)
    {
        $v = $request->validate([
            'country' => 'nullable|string|size:2',
            'operation' => 'required|in:deposit,withdraw,pay,send',
        ]);
        $country = $v['country'] ?? $request->user()->wallet?->country ?? config('flashpay.peex.default_country', 'CG');

        return response()->json($this->methods->for($country, $v['operation']));
    }

    public function payCode(Request $request)
    {
        $c = $this->cash->issuePayCode($request->user());

        return response()->json($c + ['ttl' => config('payment_methods.pay_code_ttl_seconds', 120)], 201);
    }

    public function vouchers(Request $request)
    {
        $list = WithdrawalVoucher::where('user_id', $request->user()->id)->latest()->limit(30)->get();

        return response()->json($list->map(fn ($v) => $this->voucherPayload($v, true)));
    }

    public function createVoucher(Request $request)
    {
        $v = $request->validate([
            'channel' => 'required|in:cash_pickup,atm',
            'amount' => 'required|integer|min:500',
            'country' => 'required|string|size:2',
            'beneficiary_name' => 'nullable|string|max:120',
            'beneficiary_phone' => 'nullable|string|max:25',
        ]);

        $r = $this->cash->createVoucher($request->user(), $v['channel'], $v['amount'], $v['country'], $v);

        return response()->json($this->voucherPayload($r['voucher'], true) + [
            'transaction' => app(PaymentController::class)->txPayload($r['transaction']),
        ], 201);
    }

    public function cancelVoucher(Request $request, WithdrawalVoucher $voucher)
    {
        abort_unless($voucher->user_id === $request->user()->id, 403);

        return response()->json($this->voucherPayload($this->cash->cancelVoucher($voucher), false));
    }

    // ------------------------------------------------------------ Marchand

    public function chargeCode(Request $request)
    {
        $v = $request->validate(['code' => 'required|string|max:30', 'amount' => 'required|integer|min:10']);
        $tx = $this->cash->chargeWithCode($request->user(), $v['code'], $v['amount']);

        return $this->respondTx($tx);
    }

    // ------------------------------------------------------------ Agent

    public function agentCashIn(Request $request)
    {
        $v = $request->validate([
            'client_code' => 'required_without:client_phone|nullable|string|max:30',
            'client_phone' => 'required_without:client_code|nullable|string|max:25',
            'amount' => 'required|integer|min:500',
            'otp' => 'nullable|string|max:10',
        ]);

        // §3.1.3 — client identifié par numéro : il confirme le montant avec le code reçu par SMS
        if (empty($v['client_code']) && config('security.cash_in_client_confirmation')) {
            $phone = app(\App\Services\Peex\PeexCorridors::class)->resolve((string) $v['client_phone'])['phone'];
            $client = app(\App\Services\Peex\PeexFlowService::class)->findUserByPhone($phone)
                ?? throw new \App\Exceptions\CashNetworkException('Aucun compte FlashPay pour ce numéro.');
            $otp = app(\App\Services\Security\OtpService::class);
            if (empty($v['otp'])) {
                $sent = $otp->send($client->phone, 'cash_in', ['agent_user_id' => $request->user()->id, 'amount' => $v['amount']],
                    'FlashPay: l\'agent ' . $request->user()->full_name . ' va crediter ' . number_format($v['amount'], 0, ',', ' ') . ' sur votre wallet. Code de confirmation: {code}');
                return response()->json(['confirmation_required' => true, 'client_name' => $client->full_name,
                    'message' => 'Demandez au client le code reçu par SMS pour confirmer le dépôt.'] + $sent, 202);
            }
            $ctx = $otp->verify($client->phone, 'cash_in', $v['otp']);
            if (($ctx['agent_user_id'] ?? null) !== $request->user()->id || (int) ($ctx['amount'] ?? 0) !== (int) $v['amount']) {
                throw new \App\Exceptions\CashNetworkException('Le code ne correspond pas à ce dépôt.');
            }
        }

        $tx = $this->cash->agentCashIn($request->user(), $v['client_code'] ?? null, $v['client_phone'] ?? null, $v['amount']);

        return $this->respondTx($tx);
    }

    public function agentVoucher(Request $request, string $code)
    {
        return response()->json($this->voucherPayload($this->cash->findPendingVoucher($code, 'cash_pickup'), false));
    }

    public function agentRedeem(Request $request)
    {
        $v = $request->validate(['code' => 'required|string|max:20']);
        $voucher = $this->cash->redeemVoucher($v['code'], 'cash_pickup', $request->user());

        return response()->json($this->voucherPayload($voucher, false) + [
            'transaction_id' => $voucher->transaction?->id,
            'reference' => $voucher->transaction?->reference,
            'message' => 'Remettez ' . $voucher->amount . ' ' . $voucher->currency . ' au client.']);
    }

    // ------------------------------------------------------------ Banque partenaire (GAB)

    public function atmVerify(Request $request)
    {
        $this->partner($request);
        $v = $request->validate(['code' => 'required|string|max:20']);
        $voucher = $this->cash->findPendingVoucher($v['code'], 'atm');

        return response()->json(['valid' => true, 'amount' => $voucher->amount, 'currency' => $voucher->currency, 'country' => $voucher->country]);
    }

    public function atmRedeem(Request $request)
    {
        $this->partner($request);
        $v = $request->validate(['code' => 'required|string|max:20', 'atm_ref' => 'required|string|max:64']);
        $voucher = $this->cash->redeemVoucher($v['code'], 'atm', null, $v['atm_ref']);

        return response()->json(['redeemed' => true, 'amount' => $voucher->amount, 'currency' => $voucher->currency, 'reference' => $voucher->transaction->reference]);
    }

    // ------------------------------------------------------------ Helpers

    protected function partner(Request $request): void
    {
        $key = (string) config('payment_methods.atm_partner_key');
        if ($key === '' || ! hash_equals($key, (string) $request->header('X-Partner-Key'))) {
            throw new CashNetworkException('Partenaire non autorisé.', 401);
        }
    }

    protected function respondTx(Transaction $tx)
    {
        return response()->json(app(PaymentController::class)->txPayload($tx), match ($tx->status) {
            'successful' => 201,
            'processing' => 202,
            default => 422,
        });
    }

    protected function voucherPayload(WithdrawalVoucher $v, bool $withCode): array
    {
        return [
            'id' => $v->id,
            'channel' => $v->channel,
            'country' => $v->country,
            'amount' => $v->amount,
            'fee' => $v->fee,
            'currency' => $v->currency,
            'status' => $v->status,
            'beneficiary_name' => $v->beneficiary_name,
            'expires_at' => $v->expires_at,
            'redeemed_at' => $v->redeemed_at,
            // Le code n'est renvoyé qu'à son propriétaire, et seulement tant qu'il est utilisable
            'code' => $withCode && $v->status === 'pending' ? $v->code_encrypted : null,
            'qr' => $withCode && $v->status === 'pending' ? "flashpay://cashout?c={$v->code_encrypted}" : null,
        ];
    }
}
