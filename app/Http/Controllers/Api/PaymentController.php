<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantOutlet;
use App\Models\Transaction;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use Illuminate\Http\Request;

/**
 * API de paiement interopérable (app mobile, style Wave).
 *
 *   GET  /api/pay/corridors                   pays, zones, opérateurs couverts
 *   POST /api/pay/lookup                      numéro -> pays, opérateur, utilisateur FlashPay ?
 *   POST /api/pay/quote                       devis (frais, change, montant reçu)
 *   POST /api/pay/transfer                    envoyer (wallet ou mobile -> wallet ou mobile)
 *   POST /api/pay/deposit                     recharger le wallet depuis MTN / Airtel / Orange…
 *   POST /api/pay/withdraw                    retirer vers son mobile money
 *   GET  /api/pay/merchant/{code}             infos marchand depuis un QR / code
 *   POST /api/pay/merchant                    payer un marchand (QR) depuis wallet ou mobile
 *   GET  /api/pay/transactions/{id}/status    suivi (polling en attente de validation USSD)
 */
class PaymentController extends Controller
{
    public function __construct(
        protected PeexFlowService $flows,
        protected PeexCorridors $corridors,
    ) {
    }

    public function corridors()
    {
        return response()->json([
            'sandbox' => (bool) config('flashpay.peex.sandbox'),
            'zones' => ['CEMAC' => 'XAF', 'UEMOA' => 'XOF', 'RDC' => 'CDF', 'GUINEE' => 'GNF'],
            'corridors' => $this->corridors->catalog(),
        ]);
    }

    public function lookup(Request $request)
    {
        $v = $request->validate(['phone' => 'required|string|max:25', 'country' => 'nullable|string|size:2']);
        $route = $this->corridors->resolve($v['phone'], $v['country'] ?? null, true);
        $user = $this->flows->findUserByPhone($route['phone']);

        return response()->json($route + [
            'flashpay_user' => $user && $user->hasRole('client') ? ['name' => $this->mask($user->full_name)] : null,
        ]);
    }

    public function quote(Request $request)
    {
        $v = $this->validateOperation($request, true);
        $user = $request->user();

        $q = match ($v['operation']) {
            'transfer' => $this->flows->quoteTransfer($user, $v['source'], $v['source_phone'] ?? null, $v['destination_phone'], $v['amount'], $v),
            'deposit' => match ($v['source'] ?? null) {
                'card' => $this->flows->quoteCardDeposit($user, $v['amount']),
                'bank' => $this->flows->quoteBankDeposit($user, $v['amount']),
                default => $this->flows->quoteDeposit($user, $v['source_phone'] ?? $user->phone, $v['amount'], $v),
            },
            'withdraw' => $this->flows->quoteWithdraw($user, $v['destination_phone'] ?? $user->phone, $v['amount'], $v),
            'merchant' => $this->flows->quoteMerchant($user, $this->merchant($v['merchant_code']), $v['source'], $v['source_phone'] ?? null, $v['amount'], $v),
        };

        return response()->json($this->present($q));
    }

    public function transfer(Request $request)
    {
        $v = $this->validateOperation($request->merge(['operation' => 'transfer']));
        $tx = $this->flows->transfer($request->user(), $v['source'], $v['source_phone'] ?? null, $v['destination_phone'], $v['amount'], $v);

        return $this->respond($tx);
    }

    public function deposit(Request $request)
    {
        $v = $request->validate([
            'method' => 'nullable|in:mobile_money,card,bank',
            'phone' => 'nullable|string|max:25',
            'amount' => 'required|integer|min:10',
            'source_country' => 'nullable|string|size:2',
        ]);
        $tx = match ($v['method'] ?? 'mobile_money') {
            'card' => $this->flows->cardDeposit($request->user(), $v['amount']),
            'bank' => $this->flows->bankDeposit($request->user(), $v['amount']),
            default => $this->flows->deposit($request->user(), $v['phone'] ?? $request->user()->phone, $v['amount'], $v),
        };

        return $this->respond($tx);
    }

    public function withdraw(Request $request)
    {
        $v = $request->validate([
            'phone' => 'nullable|string|max:25',
            'amount' => 'required|integer|min:10',
            'destination_country' => 'nullable|string|size:2',
        ]);
        $tx = $this->flows->withdraw($request->user(), $v['phone'] ?? $request->user()->phone, $v['amount'], $v);

        return $this->respond($tx);
    }

    public function merchantInfo(string $code)
    {
        $m = $this->merchant($code);
        $wallet = $m->user?->wallet;

        return response()->json([
            'code' => $m->qr_code_token,
            'name' => $m->business_name,
            'category' => $m->business_category,
            'country' => $wallet?->country,
            'currency' => $wallet?->currency ?? 'XAF',
            'validated' => $m->validation_status === 'approved',
        ]);
    }

    public function payMerchant(Request $request)
    {
        $v = $request->validate([
            'merchant_code' => 'required|string',
            'source' => 'required|in:wallet,mobile,card,bank',
            'source_phone' => 'required_if:source,mobile|nullable|string|max:25',
            'source_country' => 'nullable|string|size:2',
            'amount' => 'required|integer|min:10',
            'method' => 'nullable|in:qr,manual,nfc',
        ]);

        $merchant = $this->merchant($v['merchant_code']);
        $outlet = MerchantOutlet::where('qr_code_token', $v['merchant_code'])->first();

        $tx = $this->flows->payMerchant(
            $request->user(), $request->user(), $merchant, $v['source'], $v['source_phone'] ?? null,
            $v['amount'], $v['method'] ?? 'qr', ['outlet_id' => $outlet?->id, 'source_country' => $v['source_country'] ?? null],
        );

        return $this->respond($tx);
    }

    public function status(Request $request, Transaction $transaction)
    {
        $user = $request->user();
        $walletId = $user->wallet?->id;
        abort_unless(
            $transaction->initiated_by === $user->id
            || ($walletId && in_array($walletId, [$transaction->source_wallet_id, $transaction->destination_wallet_id], true))
            || $user->hasAnyRole(['super_admin', 'support']),
            403
        );

        // Sans callback PEEX ni planificateur, le statut n'avancerait jamais : l'app
        // interroge cette route toutes les 3 s, on vérifie donc auprès de PEEX
        // (au plus une fois toutes les 4 s par demande).
        if ($transaction->status === 'processing') {
            $handler = app(\App\Services\Peex\PeexStatusHandler::class);
            foreach ($transaction->peexRequests()->whereNull('finalized_at')->get() as $req) {
                if (\Illuminate\Support\Facades\Cache::add('peex:poll:' . $req->id, 1, 4)) {
                    try {
                        $handler->refresh($req);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('PEEX : vérification du statut impossible', ['track_id' => $req->track_id, 'error' => $e->getMessage()]);
                    }
                }
            }
            $transaction->refresh();
            // Délai de validation dépassé : on conclut sans attendre le planificateur
            app(\App\Services\Peex\PendingTimeoutService::class)->expireIfStale($transaction);
            $transaction->refresh();
        }

        return response()->json($this->txPayload($transaction));
    }

    // ------------------------------------------------------------------------

    protected function validateOperation(Request $request, bool $quote = false): array
    {
        return $request->validate([
            'operation' => ($quote ? 'required' : 'nullable') . '|in:transfer,deposit,withdraw,merchant',
            'source' => 'required_if:operation,transfer,merchant|in:wallet,mobile,card',
            'source_phone' => 'nullable|string|max:25',
            'source_country' => 'nullable|string|size:2',
            'destination_phone' => 'required_if:operation,transfer|nullable|string|max:25',
            'destination_country' => 'nullable|string|size:2',
            'merchant_code' => 'required_if:operation,merchant|nullable|string',
            'deliver_to' => 'nullable|in:auto,mobile',
            'beneficiary_name' => 'nullable|string|max:100',
            'purpose' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:140',
            'amount' => 'required|integer|min:10',
        ]);
    }

    protected function merchant(string $code): Merchant
    {
        $code = trim($code);
        if (str_starts_with($code, 'flashpay://')) {
            parse_str((string) parse_url($code, PHP_URL_QUERY), $q);
            $code = $q['m'] ?? $code;
        }

        $merchant = Merchant::where('qr_code_token', $code)->first()
            ?? MerchantOutlet::where('qr_code_token', $code)->first()?->merchant;

        abort_unless($merchant, 404, 'Marchand introuvable.');

        return $merchant;
    }

    protected function present(array $q): array
    {
        foreach (['source', 'destination'] as $side) {
            unset($q[$side]['balance'], $q[$side]['user_id'], $q[$side]['wallet_id']);
            if (($q[$side]['type'] ?? null) === 'wallet' && $side === 'destination') {
                $q[$side]['name'] = $this->mask($q[$side]['name'] ?? '');
            }
        }
        return $q;
    }

    protected function respond(Transaction $tx)
    {
        $code = match ($tx->status) {
            'successful' => 201,
            'processing' => 202, // en attente de validation USSD / confirmation PEEX
            default => 422,
        };

        return response()->json($this->txPayload($tx), $code);
    }

    public function txPayload(Transaction $tx): array
    {
        $tx->loadMissing('peexRequests', 'sourceWallet.user:id,full_name,phone', 'destinationWallet.user:id,full_name,phone', 'initiator:id,full_name,phone');
        $pending = $tx->status === 'processing';
        $meta = $tx->meta ?? [];
        // Récapitulatif : expéditeur, bénéficiaire et détails de l'opération (vue de l'utilisateur connecté)
        $viewer = auth()->user();
        $journal = $viewer
            ? \App\Support\TransactionPresenter::present($tx, \App\Support\TransactionPresenter::walletIdsOf($viewer), (int) $viewer->id)
            : \App\Support\TransactionPresenter::present($tx);

        return \Illuminate\Support\Arr::only($journal, ['label', 'direction', 'flow', 'channel', 'sender_name', 'sender_phone', 'beneficiary_name', 'beneficiary_phone', 'details']) + [
            'id' => $tx->id,
            'reference' => $tx->reference,
            'type' => $tx->type,
            'scope' => $tx->scope,
            'status' => $tx->status,
            'stage' => $tx->stage,
            'amount' => $tx->amount,
            // Frais réellement prélevés : 0 si l'opération a échoué ou a été remboursée
            'fee' => in_array($tx->status, ['failed', 'reversed'], true) ? 0 : $tx->fee,
            'fee_quoted' => $tx->fee,
            'currency' => $tx->currency,
            'destination_amount' => $tx->destination_amount ?? $tx->amount,
            'destination_currency' => $tx->destination_currency ?? $tx->currency,
            'merchant_fee' => $tx->merchant_fee,
            'source_rail' => $tx->source_rail,
            'destination_rail' => $tx->destination_rail,
            'source_account' => $tx->source_account,
            'destination_account' => $tx->destination_account,
            'source_operator' => $meta['source_operator'] ?? null,
            'destination_operator' => $meta['destination_operator'] ?? null,
            'failure_reason' => $tx->failure_reason,
            'checkout_url' => $pending && $tx->stage === 'awaiting_card' ? ($meta['checkout_url'] ?? null) : null,
            'message' => match (true) {
                $pending && $tx->stage === 'awaiting_card' => 'Finalisez le paiement par carte sur la page sécurisée qui s\'est ouverte.',
                $tx->status === 'successful' => 'Opération réussie.',
                $pending && $tx->stage === 'awaiting_pickup' => 'Présentez votre code de retrait ' . ($tx->destination_rail === 'atm' ? 'au GAB partenaire.' : 'à un agent FlashPay.'),
                $pending && $tx->stage === 'awaiting_source' => 'Validez le paiement sur le téléphone ' . $tx->source_account . ' (code secret mobile money).',
                $pending && $tx->stage === 'awaiting_refund' => 'Le versement au bénéficiaire a échoué : remboursement en cours sur le ' . $tx->source_account . '.',
                $pending => 'Paiement reçu, versement en cours chez l\'opérateur du bénéficiaire.',
                $tx->status === 'reversed' && $tx->source_rail === 'peex' => 'Échec du versement : vous avez été remboursé sur le ' . $tx->source_account . '.',
                $tx->status === 'reversed' => 'Échec du versement : votre wallet a été remboursé.',
                ! empty($meta['validation_timeout']) && $tx->status === 'failed' => 'Délai dépassé : le paiement n\'a pas été validé sur le téléphone. Aucun montant n\'a été débité. Vous pouvez recommencer.',
                default => $tx->failure_reason ?: 'Opération échouée.',
            },
            // Compte à rebours « Validez sur le téléphone » (secondes restantes)
            'validation_expires_at' => ($d = \App\Services\Peex\PendingTimeoutService::deadline($tx))?->toIso8601String(),
            'validation_seconds_left' => $d ? (int) max(0, now()->diffInSeconds($d, false)) : null,
            'validation_timeout_seconds' => \App\Services\Peex\PendingTimeoutService::timeoutSeconds(),
            'created_at' => $tx->created_at,
            'completed_at' => $tx->completed_at,
        ];
    }

    protected function mask(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return implode(' ', array_map(fn ($p, $i) => $i === 0 ? $p : mb_substr($p, 0, 1) . '.', $parts, array_keys($parts)));
    }
}
