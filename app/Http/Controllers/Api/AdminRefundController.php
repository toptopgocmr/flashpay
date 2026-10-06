<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Services\Peex\ManualRefundService;
use App\Services\Peex\PeexStatusHandler;
use App\Support\TransactionPresenter;
use Illuminate\Http\Request;

/** Console : remboursements manuels PEEX (mobile money / compte bancaire). */
class AdminRefundController extends Controller
{
    public function __construct(protected ManualRefundService $refunds)
    {
    }

    public function index(Transaction $transaction)
    {
        $transaction->load('peexRequests');
        $p = TransactionPresenter::parties($transaction);
        $meta = $transaction->meta ?? [];
        $mobileRail = in_array($transaction->source_rail, ['peex', 'digitwace'], true);

        return response()->json([
            'transaction' => [
                'id' => $transaction->id, 'reference' => $transaction->reference, 'status' => $transaction->status,
                'amount' => (int) $transaction->amount, 'fee' => (int) $transaction->fee, 'currency' => $transaction->currency,
            ],
            'refundable' => ManualRefundService::refundableOf($transaction),
            // Valeurs proposées : le payeur d'origine
            'defaults' => [
                'channel' => 'mobile',
                'phone' => $mobileRail ? TransactionPresenter::phone($transaction->source_account) : ($p['sender_phone']),
                'beneficiary_name' => $meta['payer_verified_name'] ?? $p['sender_name'],
                'country' => $meta['source_country'] ?? 'CG',
                'currency' => $transaction->currency,
            ],
            // Formulaire officiel PEEX : valeurs pré-remplies
            'peex' => self::peexDefaults($transaction, $p, $meta, $mobileRail),
            'refunds' => ManualRefundService::requestsOf($transaction)->map(fn ($r) => $this->refunds->present($r))->values(),
        ]);
    }

    public function store(Request $request, Transaction $transaction)
    {
        if ($request->filled('api')) {
            return $this->storePeex($request, $transaction);
        }
        $v = $request->validate([
            'channel' => 'required|in:mobile,bank',
            'amount' => 'required|integer|min:1',
            'currency' => 'nullable|string|size:3',
            'reason' => 'required|string|max:190',
            'beneficiary_name' => 'required|string|max:120',
            // Mobile money
            'phone' => 'required_if:channel,mobile|nullable|string|max:20',
            'country' => 'nullable|string|size:2',
            // Compte bancaire (PEEX › Bank Payment Request)
            'bank_name' => 'nullable|string|max:120',
            'bank_address' => 'required_if:channel,bank|nullable|string|max:190',
            'bank_iban' => 'required_if:channel,bank|nullable|string|max:40',
            'bank_swift' => 'required_if:channel,bank|nullable|string|min:6|max:11',
            'to_country' => 'required_if:channel,bank|nullable|string|size:2',
            'to_currency' => 'nullable|string|size:3',
            'mobile_phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:120',
            'purpose' => 'nullable|string|max:120',
            'fund_origin' => 'nullable|string|max:120',
        ], [
            'bank_iban.required_if' => 'IBAN / RIB obligatoire pour un remboursement bancaire.',
            'bank_swift.required_if' => 'Code SWIFT / BIC obligatoire pour un remboursement bancaire.',
            'bank_address.required_if' => 'Adresse de la banque obligatoire.',
            'to_country.required_if' => 'Pays de la banque obligatoire.',
            'phone.required_if' => 'Numéro mobile money obligatoire.',
        ]);

        $res = $this->refunds->refund($transaction, $v, $request->user());
        $msg = match ($res['status']) {
            'failed' => 'Remboursement refusé par PEEX : ' . ($res['error'] ?? 'erreur'),
            'successful' => "Remboursement effectué ({$res['track_id']}).",
            default => "Remboursement envoyé à PEEX ({$res['track_id']}) : en cours de traitement.",
        };

        return response()->json(['message' => $msg] + $res, $res['status'] === 'failed' ? 422 : 201);
    }

    /** Formulaire officiel PEEX (disbursement/request_payment, clients/request_payment, clients/request_bank_payment). */
    protected function storePeex(Request $request, Transaction $transaction)
    {
        $api = $request->input('api');
        $remit = in_array($api, ['remittance', 'bank'], true);
        $v = $request->validate([
            'api' => 'required|in:disbursement,remittance,bank',
            'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:190',
            'sender_first_name' => 'required|string|max:60',
            'sender_last_name' => 'required|string|max:60',
            'sender_mobile_phone' => 'required|string|max:20',
            'first_name' => 'required|string|max:60',
            'last_name' => 'required|string|max:60',
            'purpose' => 'required|string|max:120',
            'fund_origin' => 'required|string|max:120',
            'mobile_phone' => ($api === 'bank' ? 'nullable' : 'required') . '|string|max:20',
            // disbursement
            'currency' => ($api === 'disbursement' ? 'required' : 'nullable') . '|string|size:3',
            'country' => ($api === 'disbursement' ? 'required' : 'nullable') . '|string|size:2',
            // remittance / bank
            'from_currency' => ($remit ? 'required' : 'nullable') . '|string|size:3',
            'to_currency' => 'nullable|string|size:3',
            'fxrate' => ($remit ? 'required' : 'nullable') . '|numeric|gt:0',
            'aml_cft' => ($remit ? 'accepted' : 'nullable'),
            'sender_country' => ($remit ? 'required' : 'nullable') . '|string|size:2',
            'to_country' => ($remit ? 'required' : 'nullable') . '|string|size:2',
            'email' => 'nullable|email|max:120',
            'sender_email' => 'nullable|email|max:120',
            'sender_city' => 'nullable|string|max:80',
            // bank
            'bank_name' => 'nullable|string|max:120',
            'bank_address' => ($api === 'bank' ? 'required' : 'nullable') . '|string|max:190',
            'bank_iban' => ($api === 'bank' ? 'required' : 'nullable') . '|string|max:40',
            'bank_swift' => ($api === 'bank' ? 'required' : 'nullable') . '|string|min:6|max:11',
        ], [
            'aml_cft.accepted' => 'Cochez la confirmation LCB-FT (aml_cft = 1) exigée par PEEX.',
        ]);

        $res = $this->refunds->refund($transaction, $v, $request->user());
        $msg = match ($res['status']) {
            'failed' => 'Remboursement refusé par PEEX : ' . ($res['error'] ?? 'erreur'),
            'successful' => "Remboursement effectué ({$res['track_id']}).",
            default => "Remboursement envoyé à PEEX ({$res['track_id']}) : en cours de traitement.",
        };

        return response()->json(['message' => $msg] + $res, $res['status'] === 'failed' ? 422 : 201);
    }

    protected static function peexDefaults(Transaction $tx, array $p, array $meta, bool $mobileRail): array
    {
        $country = strtoupper($meta['source_country'] ?? 'CG');
        $name = trim((string) ($meta['payer_verified_name'] ?? $p['sender_name'] ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = array_shift($parts) ?: '';
        $sender = (string) config('flashpay.peex.sender_name', 'FlashPay Remboursement');
        $sp = preg_split('/\s+/', trim($sender)) ?: ['FlashPay'];
        $payoutApi = null;
        try {
            $payoutApi = app(\App\Services\Peex\PeexCorridors::class)->country($country)['payout_api'] ?? null;
        } catch (\Throwable) {
        }

        return [
            'api' => $payoutApi === 'remittance' ? 'remittance' : 'disbursement',
            'amount' => ManualRefundService::refundableOf($tx),
            'currency' => $tx->currency ?: 'XAF', 'from_currency' => $tx->currency ?: 'XAF', 'to_currency' => $tx->currency ?: 'XAF', 'fxrate' => 1,
            'sender_first_name' => array_shift($sp) ?: 'FlashPay', 'sender_last_name' => implode(' ', $sp) ?: 'Remboursement',
            'sender_mobile_phone' => (string) config('flashpay.peex.sender_phone', ''),
            'sender_country' => (string) config('flashpay.peex.sender_country', 'CG'), 'sender_email' => (string) config('mail.from.address', ''), 'sender_city' => 'Brazzaville',
            'first_name' => $first, 'last_name' => implode(' ', $parts),
            'mobile_phone' => $mobileRail ? TransactionPresenter::phone($tx->source_account) : ($p['sender_phone'] ?? ''),
            'country' => $country, 'to_country' => $country,
            'purpose' => 'FAMILY', 'fund_origin' => in_array($fo = strtoupper((string) config('flashpay.peex.default_fund_origin', 'SALARY')), ['SALARY', 'SALES_AND_BUSINESS_DEVELOPMENT', 'INVESTMENT'], true) ? $fo : 'SALARY',
        ];
    }

    /** Relance la vérification du statut auprès de PEEX. */
    public function refresh(Transaction $transaction, PeexRequest $peexRequest, PeexStatusHandler $handler)
    {
        abort_unless($peexRequest->transaction_id === $transaction->id, 404);
        $handler->refresh($peexRequest);

        return response()->json(['request' => $this->refunds->present($peexRequest->fresh())]);
    }
}
