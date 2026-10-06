<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use App\Services\Connectors\PeexConnector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * FlashPay Switch — cœur du routage des paiements interopérables.
 *
 * Orchestration (ex: MTN MoMo -> Airtel Money, tous deux via PEEX) :
 *   1. SOURCE      : débit wallet interne OU collect() sur le rail source
 *   2. SUSPENSE    : fonds logés dans le compte d'attente flashpay:suspense (ledger)
 *   3. DESTINATION : crédit wallet interne OU disburse() sur le rail destination
 *
 * Rails asynchrones (PEEX) : si le rail répond "pending", la transaction reste
 * en "processing" avec stage = awaiting_source | awaiting_destination, puis
 * reprend via onSourceConfirmed()/onDestinationConfirmed() (ou *Failed())
 * à la réception du callback PEEX ou lors du polling `php artisan peex:sync`.
 */
class SwitchService
{
    public function __construct(
        protected WalletService $walletService,
        protected LedgerService $ledgerService,
    ) {
    }

    public function connectorFor(string $rail): PaymentRailConnector
    {
        // Digitwace / WacePay : versements vers les pays configurés
        if ($rail === 'digitwace' && config('flashpay.rails.digitwace.enabled', false)) {
            return app(\App\Services\Digitwace\DigitwaceConnector::class);
        }
        // PEEX : collecte et versements (le wallet interne n'a pas de connecteur).
        if ($rail !== 'peex' || ! config('flashpay.rails.peex.enabled', false)) {
            throw new \InvalidArgumentException("Rail de paiement non disponible : {$rail}");
        }

        return app(PeexConnector::class);
    }

    /**
     * @param array{
     *   type: string, source_rail: string, destination_rail: string,
     *   source_account?: ?string, destination_account?: ?string,
     *   source_wallet_id?: ?int, destination_wallet_id?: ?int,
     *   amount: int, fee?: int, currency?: string, initiated_by: int, meta?: array
     * } $payload
     */
    public function process(array $payload): Transaction
    {
        return $this->runSource($this->createTransaction($payload));
    }

    /**
     * Crée la transaction sans lancer la source : utilisé quand la source est
     * confirmée plus tard par un tiers (ex. page de paiement carte 3-D Secure),
     * qui appellera onSourceConfirmed() / onSourceFailed().
     */
    public function createPending(array $payload, string $stage = 'awaiting_source'): Transaction
    {
        $tx = $this->createTransaction($payload);
        $tx->update(['stage' => $stage]);
        return $tx->fresh();
    }

    protected function createTransaction(array $payload): Transaction
    {
        // Contrôles bloquants : canal disponible, compte non bloqué, anti-fraude, plafonds KYC (§4.5, §12, §14)
        app(\App\Services\Compliance\ComplianceGuard::class)->check($payload);

        return Transaction::create([
            'reference' => 'FP-' . strtoupper(Str::random(12)),
            'type' => $payload['type'],
            'source_rail' => $payload['source_rail'],
            'destination_rail' => $payload['destination_rail'],
            'source_account' => $payload['source_account'] ?? null,
            'destination_account' => $payload['destination_account'] ?? null,
            'source_wallet_id' => $payload['source_wallet_id'] ?? null,
            'destination_wallet_id' => $payload['destination_wallet_id'] ?? null,
            'amount' => $payload['amount'],
            'fee' => $payload['fee'] ?? 0,
            'merchant_fee' => $payload['merchant_fee'] ?? 0,
            'currency' => $payload['currency'] ?? 'XAF',
            'destination_amount' => $payload['destination_amount'] ?? null,
            'destination_currency' => $payload['destination_currency'] ?? null,
            'scope' => $payload['scope'] ?? null,
            'initiated_by' => $payload['initiated_by'],
            'status' => 'processing',
            'meta' => $payload['meta'] ?? null,
        ]);
    }

    // ------------------------------------------------------------ Étape 1

    protected function runSource(Transaction $tx): Transaction
    {
        try {
            if ($tx->source_rail === 'wallet') {
                // Débit montant + frais et écritures dans la même transaction SQL :
                // si quoi que ce soit échoue, rien n'est prélevé (ni montant, ni frais).
                DB::transaction(function () use ($tx) {
                    $wallet = Wallet::findOrFail($tx->source_wallet_id);
                    $this->walletService->debit($wallet, $tx->amount + $tx->fee);
                    $this->ledgerService->recordDoubleEntry($tx, "wallet:{$wallet->id}", 'flashpay:suspense', $tx->amount);
                    $this->recordFee($tx, "wallet:{$wallet->id}");
                });
            } else {
                $result = $this->connectorFor($tx->source_rail)
                    ->collect($tx->source_account, $tx->amount + $tx->fee, $tx->currency, $tx->reference);
                $tx->update(['source_external_ref' => $result['external_ref']]);

                if ($result['status'] === 'pending') {
                    $tx->update(['stage' => 'awaiting_source']);
                    return $tx->fresh();
                }
                if ($result['status'] === 'failed' && ($fallback = $this->collectFallback($tx, $result))) {
                    $result = $fallback;
                    if ($result['status'] === 'pending') {
                        $tx->update(['stage' => 'awaiting_source']);
                        return $tx->fresh();
                    }
                }
                if ($result['status'] !== 'successful') {
                    return $this->fail($tx, 'Échec de la collecte (' . $tx->source_rail . ') : ' . $this->reason($result));
                }
                $this->ledgerService->recordDoubleEntry($tx, "{$tx->source_rail}:{$tx->source_account}", 'flashpay:suspense', $tx->amount);
                $this->recordFee($tx, "{$tx->source_rail}:{$tx->source_account}");
            }
        } catch (\Throwable $e) {
            return $this->fail($tx, $e->getMessage());
        }

        return $this->runDestination($tx);
    }

    /**
     * Collecte WacePay refusée (rien n'a été prélevé) : on réessaie une fois via PEEX
     * si PEEX couvre le pays du payeur. Renvoie le résultat PEEX, ou null.
     */
    protected function collectFallback(Transaction $tx, array $wace): ?array
    {
        if ($tx->source_rail !== 'digitwace' || ! config('flashpay.digitwace.collect_fallback_peex', true) || ! config('flashpay.rails.peex.enabled', false)) {
            return null;
        }
        try {
            $route = app(\App\Services\Peex\PeexCorridors::class)->resolve((string) $tx->source_account, $tx->meta['source_country'] ?? null);
            $peexCountry = config('flashpay.corridors.' . $route['country']);
            if (! $peexCountry || ($peexCountry['collect'] ?? true) === false) {
                return null; // pays hors PEEX
            }
            \Illuminate\Support\Facades\Log::warning('Collecte WacePay refusée : nouvel essai via PEEX', ['reference' => $tx->reference, 'wacepay' => $this->reason($wace)]);
            $tx->update(['source_rail' => 'peex', 'meta' => ($tx->meta ?? []) + ['wacepay_collect_error' => mb_substr($this->reason($wace), 0, 250)]]);
            $result = $this->connectorFor('peex')->collect($tx->source_account, $tx->amount + $tx->fee, $tx->currency, $tx->reference);
            $tx->update(['source_external_ref' => $result['external_ref']]);
            return $result;
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'raw' => ['error' => 'WacePay : ' . $this->reason($wace) . ' ; PEEX : ' . $e->getMessage()]];
        }
    }

    public function onSourceConfirmed(Transaction $tx): Transaction
    {
        return $this->withLock($tx, 'awaiting_source', function (Transaction $tx) {
            $this->ledgerService->recordDoubleEntry($tx, "{$tx->source_rail}:{$tx->source_account}", 'flashpay:suspense', $tx->amount);
            $this->recordFee($tx, "{$tx->source_rail}:{$tx->source_account}");
            $tx->update(['stage' => null]);
            return $this->runDestination($tx);
        });
    }

    public function onSourceFailed(Transaction $tx, string $reason): Transaction
    {
        return $this->withLock($tx, 'awaiting_source', fn (Transaction $tx) => $this->fail($tx, 'Collecte refusée : ' . $reason));
    }

    // ------------------------------------------------------------ Étape 2

    protected function runDestination(Transaction $tx): Transaction
    {
        try {
            [$out, $outCur] = $this->outAmount($tx);
            $this->recordFx($tx, $out, $outCur);

            if ($tx->destination_rail === 'wallet') {
                $wallet = Wallet::findOrFail($tx->destination_wallet_id);
                $net = max(0, $out - $tx->merchant_fee);
                $this->walletService->credit($wallet, $net);
                $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', "wallet:{$wallet->id}", $net, $outCur);
                if ($tx->merchant_fee > 0) {
                    $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', 'flashpay:fees', $tx->merchant_fee, $outCur, 'Commission marchand');
                }
                return $this->complete($tx);
            }

            $result = $this->connectorFor($tx->destination_rail)
                ->disburse($tx->destination_account, $out, $outCur, $tx->reference);
            $tx->update(['destination_external_ref' => $result['external_ref']]);

            if ($result['status'] === 'pending') {
                $tx->update(['stage' => 'awaiting_destination']);
                return $tx->fresh();
            }
            if ($result['status'] !== 'successful') {
                return $this->failAndRefund($tx, 'Échec du décaissement (' . $tx->destination_rail . ') : ' . $this->reason($result));
            }
            $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', "{$tx->destination_rail}:{$tx->destination_account}", $out, $outCur);

            return $this->complete($tx);
        } catch (\Throwable $e) {
            return $this->failAndRefund($tx, $e->getMessage());
        }
    }

    public function onDestinationConfirmed(Transaction $tx): Transaction
    {
        return $this->withLock($tx, 'awaiting_destination', function (Transaction $tx) {
            [$out, $outCur] = $this->outAmount($tx);
            $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', "{$tx->destination_rail}:{$tx->destination_account}", $out, $outCur);
            return $this->complete($tx);
        });
    }

    public function onDestinationFailed(Transaction $tx, string $reason): Transaction
    {
        return $this->withLock($tx, 'awaiting_destination', fn (Transaction $tx) => $this->failAndRefund($tx, 'Décaissement refusé : ' . $reason));
    }

    // ------------------------------------------------------------ Helpers

    /** Exécute $fn seulement si la transaction est bien à l'étape attendue (idempotence callback/polling). */
    protected function withLock(Transaction $tx, string $expectedStage, callable $fn): Transaction
    {
        $locked = DB::transaction(function () use ($tx, $expectedStage) {
            $fresh = Transaction::whereKey($tx->id)->lockForUpdate()->first();
            if ($fresh->status !== 'processing' || $fresh->stage !== $expectedStage) {
                return null;
            }
            $fresh->update(['stage' => $expectedStage . ':handling']);
            return $fresh;
        });

        if (! $locked) {
            return $tx->fresh();
        }

        $locked->stage = $expectedStage;
        return $fn($locked);
    }

    protected function complete(Transaction $tx): Transaction
    {
        $tx->update(['status' => 'successful', 'stage' => null, 'completed_at' => now()]);
        return $this->notified($tx->fresh());
    }

    protected function fail(Transaction $tx, string $reason): Transaction
    {
        $tx->update(['status' => 'failed', 'stage' => null, 'failure_reason' => mb_substr($reason, 0, 250)]);
        return $this->notified($tx->fresh());
    }

    /** Notifications par profil (§11) + suites métier (payment intents, demandes de paiement…). */
    protected function notified(Transaction $tx): Transaction
    {
        app(\App\Services\Notifications\TransactionNotifier::class)->handle($tx);
        app(\App\Services\Merchant\PaymentRequestService::class)->onTransactionFinal($tx);
        return $tx;
    }

    /**
     * Échec après que les fonds ont quitté la source :
     *  - source wallet   -> remboursement automatique (montant + frais), statut "reversed"
     *  - source externe  -> fonds conservés en suspense, remboursement manuel (Support)
     */
    protected function failAndRefund(Transaction $tx, string $reason): Transaction
    {
        if ($tx->source_rail === 'wallet' && $tx->source_wallet_id) {
            $wallet = Wallet::find($tx->source_wallet_id);
            $this->walletService->credit($wallet, $tx->amount + $tx->fee);
            $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', "wallet:{$wallet->id}", $tx->amount, null, 'Remboursement');
            if ($tx->fee > 0) {
                $this->ledgerService->recordDoubleEntry($tx, 'flashpay:fees', "wallet:{$wallet->id}", $tx->fee, null, 'Remboursement des frais');
            }
            $tx->update(['status' => 'reversed', 'stage' => null, 'failure_reason' => mb_substr($reason . ' — wallet remboursé', 0, 250)]);
            return $this->notified($tx->fresh());
        }

        // Source mobile money (PEEX) : remboursement automatique sur le numéro débité.
        // N'est appelé qu'après un échec de versement CERTAIN (vérifié auprès de PEEX).
        if ($tx->source_rail === 'peex' && $tx->source_account) {
            return $this->startRefund($tx, $reason);
        }

        // Source carte bancaire ou collecte WacePay : montant ET frais recrédités
        // immédiatement sur le wallet FlashPay du client (aucun frais conservé).
        if (in_array($tx->source_rail, ['card', 'bank', 'digitwace'], true) && ($wallet = $this->refundWalletOf($tx))) {
            return DB::transaction(function () use ($tx, $wallet, $reason) {
                $from = "{$tx->source_rail}:" . ($tx->source_account ?: 'client');
                $this->walletService->credit($wallet, $tx->amount + $tx->fee);
                $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', "wallet:{$wallet->id}", $tx->amount, null, 'Remboursement sur wallet');
                if ($tx->fee > 0) {
                    $this->ledgerService->recordDoubleEntry($tx, 'flashpay:fees', "wallet:{$wallet->id}", $tx->fee, null, 'Remboursement des frais');
                }
                $tx->update(['status' => 'reversed', 'stage' => null, 'failure_reason' => mb_substr($reason . ' — montant et frais remboursés sur votre wallet FlashPay', 0, 250),
                    'meta' => ($tx->meta ?? []) + ['refunded_to' => "wallet:{$wallet->id}", 'refunded_from' => $from]]);
                return $this->notified($tx->fresh());
            });
        }

        return $this->fail($tx, $reason . ' — fonds collectés en suspense, remboursement manuel requis');
    }

    /** Wallet FlashPay du client à recréditer (initiateur, ou wallet destinataire d'une recharge). */
    protected function refundWalletOf(Transaction $tx): ?Wallet
    {
        if ($tx->destination_rail === 'wallet' && $tx->destination_wallet_id && in_array($tx->type, ['cash_in', 'deposit'], true)) {
            return Wallet::find($tx->destination_wallet_id);
        }
        return Wallet::where('user_id', $tx->initiated_by)->orderBy('id')->first();
    }

    /** Lance le remboursement PEEX du payeur (montant + frais). */
    protected function startRefund(Transaction $tx, string $reason): Transaction
    {
        $reason = mb_substr($reason, 0, 180);
        $tx->update(['stage' => 'awaiting_refund', 'failure_reason' => $reason . ' — remboursement en cours']);

        try {
            $result = app(PeexConnector::class)->refund($tx->fresh());
        } catch (\Throwable $e) {
            $result = ['status' => 'failed', 'raw' => ['error' => $e->getMessage()]];
        }

        if ($result['status'] === 'pending') {
            return $tx->fresh();
        }
        if ($result['status'] === 'successful') {
            return $this->refunded($tx->fresh(), $reason);
        }

        Log::critical('Remboursement PEEX impossible : remboursement manuel requis', ['reference' => $tx->reference, 'error' => $this->reason($result)]);
        return $this->fail($tx, $reason . ' — remboursement automatique impossible (' . $this->reason($result) . '), remboursement manuel requis');
    }

    public function onRefundConfirmed(Transaction $tx): Transaction
    {
        return $this->withLock($tx, 'awaiting_refund', fn (Transaction $tx) => $this->refunded($tx, (string) $tx->failure_reason));
    }

    public function onRefundFailed(Transaction $tx, string $reason): Transaction
    {
        return $this->withLock($tx, 'awaiting_refund', function (Transaction $tx) use ($reason) {
            Log::critical('Remboursement PEEX refusé : remboursement manuel requis', ['reference' => $tx->reference, 'reason' => $reason]);
            return $this->fail($tx, str_replace(' — remboursement en cours', '', (string) $tx->failure_reason) . ' — remboursement refusé (' . $reason . '), remboursement manuel requis');
        });
    }

    /** Remboursement confirmé : écritures inverses et statut « reversed ». */
    protected function refunded(Transaction $tx, string $reason): Transaction
    {
        $payer = "{$tx->source_rail}:{$tx->source_account}";
        $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', $payer, $tx->amount, null, 'Remboursement');
        if ($tx->fee > 0) {
            $this->ledgerService->recordDoubleEntry($tx, 'flashpay:fees', $payer, $tx->fee, null, 'Remboursement des frais');
        }
        $reason = str_replace(' — remboursement en cours', '', $reason);
        $tx->update(['status' => 'reversed', 'stage' => null, 'failure_reason' => mb_substr($reason . ' — remboursé sur ' . $tx->source_account, 0, 250)]);

        return $this->notified($tx->fresh());
    }

    /** Montant et devise versés au bénéficiaire (après change éventuel). */
    protected function outAmount(Transaction $tx): array
    {
        return [
            (int) ($tx->destination_amount ?? $tx->amount),
            $tx->destination_currency ?: $tx->currency,
        ];
    }

    /** Frais client : du payeur vers le compte de produits FlashPay. */
    protected function recordFee(Transaction $tx, string $from): void
    {
        if ($tx->fee > 0) {
            $this->ledgerService->recordDoubleEntry($tx, $from, 'flashpay:fees', $tx->fee, null, 'Frais FlashPay');
        }
    }

    /** Opération de change : sortie en devise source, entrée en devise cible. */
    protected function recordFx(Transaction $tx, int $out, string $outCur): void
    {
        if ($outCur !== $tx->currency) {
            $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', "flashpay:fx:{$tx->currency}", $tx->amount, $tx->currency, "Change {$tx->currency} → {$outCur}");
            $this->ledgerService->recordDoubleEntry($tx, "flashpay:fx:{$outCur}", 'flashpay:suspense', $out, $outCur, "Change {$tx->currency} → {$outCur}");
        }
    }

    protected function reason(array $result): string
    {
        $raw = $result['raw'] ?? [];
        return (string) ($raw['error'] ?? $raw['message'] ?? ($result['status'] ?? 'inconnu'));
    }
}
