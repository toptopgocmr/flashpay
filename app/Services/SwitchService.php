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
        // Transferts internationaux (versement) : WacePay API v2 / TerraPay
        if ($rail === 'wacepay_remit' && config('flashpay.rails.wacepay_remit.enabled', false)) {
            return app(\App\Services\Remit\WacepayRemitConnector::class);
        }
        if ($rail === 'terrapay' && config('flashpay.rails.terrapay.enabled', false)) {
            return app(\App\Services\Remit\TerrapayConnector::class);
        }
        if ($rail === 'pawapay' && config('flashpay.rails.pawapay.enabled', false)) {
            return app(\App\Services\Remit\PawapayConnector::class);
        }
        // pawaPay passerelle nationale : collecte (deposits) + versements (payouts) mobile money
        // MaishaPay : collecte mobile money, versements B2C, remboursements
        if ($rail === 'maishapay' && config('flashpay.rails.maishapay.enabled', false)) {
            return app(\App\Services\Maishapay\MaishapayConnector::class);
        }
        if ($rail === 'pawapay_momo' && config('flashpay.rails.pawapay_momo.enabled', false)) {
            return app(\App\Services\Remit\PawapayMomoConnector::class);
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
                if ($result['status'] === 'failed' && ($fallback = $this->collectFailover($tx, $result) ?? $this->collectFallback($tx, $result))) {
                    $result = $fallback;
                    $tx->refresh();
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
     * Collecte REJETÉE par l'API du partenaire (rien n'a été prélevé) : nouvel essai via la
     * passerelle de secours du corridor (mode Mixte ou Automatique). Renvoie le dernier
     * résultat, ou null s'il n'y a pas d'alternative.
     */
    protected function collectFailover(Transaction $tx, array $result): ?array
    {
        if (! in_array($tx->source_rail, \App\Services\Payments\PayoutFailover::RAILS, true)) {
            return null;
        }
        $iso = strtoupper((string) ($tx->meta['source_country'] ?? ''));
        if ($iso === '') {
            try {
                $iso = app(\App\Services\Peex\PeexCorridors::class)->resolve((string) $tx->source_account)['country'];
            } catch (\Throwable) {
                return null;
            }
        }
        $routing = app(\App\Services\Payments\GatewayRouting::class);
        $switched = false;
        while ($result['status'] === 'failed') {
            $meta = $tx->meta ?? [];
            $attempts = (array) ($meta['collect_attempts'] ?? []);
            $tried = array_merge(array_column($attempts, 'rail'), [$tx->source_rail]);
            $next = $routing->nextCollect($iso, $tried, $tx->source_rail);
            if (! $next) {
                break;
            }
            $attempts[] = ['rail' => $tx->source_rail, 'partner' => $this->partnerLabel($tx->source_rail),
                'error' => mb_substr($this->reason($result), 0, 200), 'ref' => $tx->source_external_ref, 'at' => now()->toIso8601String()];
            Log::warning('Collecte : bascule automatique de partenaire', ['reference' => $tx->reference, 'from' => $tx->source_rail, 'to' => $next, 'error' => $this->reason($result)]);
            $tx->update(['source_rail' => $next, 'source_external_ref' => null, 'meta' => array_merge($meta, ['collect_attempts' => $attempts])]);
            $tx = $tx->fresh();
            try {
                $result = $this->connectorFor($next)->collect($tx->source_account, $tx->amount + $tx->fee, $tx->currency, $tx->reference);
            } catch (\Throwable $e) {
                $result = ['status' => 'failed', 'external_ref' => null, 'raw' => ['error' => $e->getMessage()]];
            }
            $tx->update(['source_external_ref' => $result['external_ref'] ?? null]);
            $switched = true;
        }
        return $switched ? $result : null;
    }

    /**
     * Collecte WacePay refusée (rien n'a été prélevé) : on réessaie une fois via PEEX
     * si PEEX couvre le pays du payeur. Renvoie le résultat PEEX, ou null.
     */
    protected function collectFallback(Transaction $tx, array $wace): ?array
    {
        if ($tx->source_rail !== 'digitwace' || ! config('flashpay.digitwace.collect_fallback_peex', false) || ! config('flashpay.rails.peex.enabled', false)) {
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

    protected function runDestination(Transaction $tx, bool $retry = false): Transaction
    {
        try {
            [$out, $outCur] = $this->outAmount($tx);
            if (! $retry) {
                $this->recordFx($tx, $out, $outCur);
            }

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
                // Échec certain chez ce partenaire (rien versé) : on essaie un autre partenaire disponible
                return $this->failover($tx, $this->reason($result))
                    ?? $this->failAndRefund($tx, $this->partnerLabel($tx->destination_rail) . ' : ' . $this->reason($result));
            }
            $this->ledgerService->recordDoubleEntry($tx, 'flashpay:suspense', "{$tx->destination_rail}:{$tx->destination_account}", $out, $outCur);

            return $this->complete($tx);
        } catch (\InvalidArgumentException $e) {
            // Rail indisponible (désactivé) : rien n'est parti
            return $this->failover($tx, $e->getMessage()) ?? $this->failAndRefund($tx, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->failAndRefund($tx, $e->getMessage());
        }
    }

    /**
     * Bascule du versement vers un autre partenaire (PEEX, WacePay, PawaPay) quand le
     * partenaire actuel a refusé de façon CERTAINE. Retourne null s'il n'y a pas d'alternative.
     */
    protected function failover(Transaction $tx, string $reason): ?Transaction
    {
        $next = app(\App\Services\Payments\PayoutFailover::class)->next($tx);
        if (! $next) {
            return null;
        }
        $meta = $tx->meta ?? [];
        $attempts = (array) ($meta['payout_attempts'] ?? []);
        $attempts[] = ['rail' => $tx->destination_rail, 'partner' => $this->partnerLabel($tx->destination_rail),
            'error' => mb_substr($reason, 0, 200), 'ref' => $tx->destination_external_ref, 'at' => now()->toIso8601String()];
        Log::warning('Versement : bascule automatique de partenaire', ['reference' => $tx->reference, 'from' => $tx->destination_rail, 'to' => $next, 'error' => $reason]);
        $tx->update(['destination_rail' => $next, 'destination_external_ref' => null, 'stage' => 'awaiting_destination',
            'meta' => array_merge($meta, ['payout_attempts' => $attempts])]);

        return $this->runDestination($tx->fresh(), retry: true);
    }

    protected function partnerLabel(?string $rail): string
    {
        return \App\Services\Payments\PayoutFailover::NAMES[$rail] ?? match ($rail) {
            'wacepay_remit' => 'WacePay', 'terrapay' => 'TerraPay', 'pawapay' => 'PawaPay', 'maishapay' => 'MaishaPay', default => ucfirst((string) $rail),
        };
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
        return $this->withLock($tx, 'awaiting_destination', fn (Transaction $tx) => $this->failover($tx, $reason)
            ?? $this->failAndRefund($tx, (str_starts_with($reason, $this->partnerLabel($tx->destination_rail)) ? '' : $this->partnerLabel($tx->destination_rail) . ' : ') . $reason));
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
        // On ne rembourse QUE si l'expéditeur a été débité et que le bénéficiaire n'a pas été crédité
        $outcome = (array) (($tx->fresh()?->meta ?? [])['outcome'] ?? []);
        if (($outcome['credit']['done'] ?? false) === true || ($outcome['refund']['done'] ?? false) === true) {
            Log::warning('Remboursement ignoré : bénéficiaire déjà crédité ou client déjà remboursé', ['reference' => $tx->reference]);
            return $tx->fresh();
        }
        // Débit effectué, crédit en échec : on le garde sur la transaction (console, app, reçus)
        $tx = $this->noteOutcome($tx, ['credit' => ['done' => false, 'error' => mb_substr($reason, 0, 200),
            'partner' => $this->partnerLabel($tx->destination_rail), 'account' => $tx->destination_account]]);
        $reason = $this->debitCreditText($tx, $reason);

        // Source wallet FlashPay → retour sur le même wallet
        if ($tx->source_rail === 'wallet' && $tx->source_wallet_id) {
            return DB::transaction(function () use ($tx, $reason) {
                $wallet = Wallet::find($tx->source_wallet_id);
                $this->walletService->credit($wallet, $tx->amount + $tx->fee);
                $refund = $this->recordRefund($tx, 'wallet', null, $wallet, 'FlashPay');
                $tx->update(['status' => 'reversed', 'stage' => null, 'failure_reason' => mb_substr($reason . ' · Remboursé sur votre wallet', 0, 250)]);
                $this->noteOutcome($tx, ['refund' => ['done' => true, 'to' => 'wallet', 'reference' => $refund->reference, 'at' => now()->toIso8601String()]]);
                return $this->notified($tx->fresh());
            });
        }

        // Source mobile money : remboursement sur le numéro débité, PAR LE PARTENAIRE QUI A COLLECTÉ
        // (PEEX, WacePay, PawaPay). N'est appelé qu'après un échec de versement CERTAIN.
        // Si ce partenaire ne peut pas rembourser, repli sur le wallet FlashPay du client.
        if ($this->canRefundToSource($tx)) {
            return $this->startRefund($tx, $reason);
        }

        // Carte bancaire / compte bancaire (pas de remboursement par API chez le partenaire),
        // ou partenaire de collecte indisponible : montant ET frais recrédités sur le wallet FlashPay.
        if (in_array($tx->source_rail, ['card', 'bank', 'crypto', 'peex', 'digitwace', 'pawapay_momo', 'maishapay'], true)
            && ($done = $this->refundToWallet($tx, $reason, in_array($tx->source_rail, ['card', 'bank', 'crypto'], true) ? 'Remboursement sur la carte / le compte bancaire / en crypto non disponible chez le partenaire' : null))) {
            return $done;
        }

        $this->noteOutcome($tx, ['refund' => ['done' => false, 'to' => 'manual']]);
        return $this->fail($tx, $reason . ' · Fonds en attente, remboursement manuel par le support');
    }

    /** Montant + frais recrédités sur le wallet FlashPay du client (aucun frais conservé). Null si pas de wallet. */
    protected function refundToWallet(Transaction $tx, string $reason, ?string $why = null): ?Transaction
    {
        $wallet = $this->refundWalletOf($tx);
        if (! $wallet) {
            return null;
        }
        return DB::transaction(function () use ($tx, $wallet, $reason, $why) {
            $from = "{$tx->source_rail}:" . ($tx->source_account ?: 'client');
            $this->walletService->credit($wallet, $tx->amount + $tx->fee);
            $refund = $this->recordRefund($tx, 'wallet', null, $wallet, 'FlashPay', $why);
            $meta = array_merge($tx->fresh()->meta ?? [], ['refunded_to' => "wallet:{$wallet->id}", 'refunded_from' => $from]);
            $meta['outcome']['refund'] = ['done' => true, 'to' => 'wallet', 'why' => $why ? mb_substr($why, 0, 200) : null, 'reference' => $refund->reference, 'at' => now()->toIso8601String()];
            $tx->update(['status' => 'reversed', 'stage' => null, 'meta' => $meta,
                'failure_reason' => mb_substr($reason . ' · Montant et frais remboursés sur votre wallet FlashPay', 0, 250)]);
            return $this->notified($tx->fresh());
        });
    }

    /** Fusionne $data dans meta.outcome (débit / crédit / remboursement). */
    protected function noteOutcome(Transaction $tx, array $data): Transaction
    {
        $tx = $tx->fresh() ?? $tx;
        $meta = $tx->meta ?? [];
        $meta['outcome'] = array_replace_recursive(['debit' => [
            'done' => true, 'amount' => (int) $tx->amount + (int) $tx->fee, 'currency' => $tx->currency,
            'account' => $tx->source_rail === 'wallet' ? 'wallet' : $tx->source_account, 'partner' => $tx->source_rail === 'wallet' ? 'FlashPay' : $this->partnerLabel($tx->source_rail),
        ]], (array) ($meta['outcome'] ?? []), $data);
        $tx->forceFill(['meta' => $meta])->save();
        return $tx;
    }

    /** « Débit effectué : 101 XAF sur +242… · Crédit en échec vers +242… (PEEX : …) » */
    protected function debitCreditText(Transaction $tx, string $reason): string
    {
        $from = $tx->source_rail === 'wallet' ? 'votre wallet FlashPay' : (string) $tx->source_account;
        $to = $tx->destination_account ?: 'le bénéficiaire';
        $tried = array_column((array) ($tx->meta['payout_attempts'] ?? []), 'partner');
        $via = $tried ? ' (essais : ' . implode(', ', array_merge($tried, [$this->partnerLabel($tx->destination_rail)])) . ')' : '';
        return 'Débit effectué : ' . number_format((int) $tx->amount + (int) $tx->fee, 0, ',', ' ') . " {$tx->currency} sur {$from} · Crédit en échec vers {$to}{$via} : "
            . mb_substr($reason, 0, 90);
    }

    /** Wallet FlashPay du client à recréditer (initiateur, ou wallet destinataire d'une recharge). */
    protected function refundWalletOf(Transaction $tx): ?Wallet
    {
        if ($tx->destination_rail === 'wallet' && $tx->destination_wallet_id && in_array($tx->type, ['cash_in', 'deposit'], true)) {
            return Wallet::find($tx->destination_wallet_id);
        }
        return Wallet::where('user_id', $tx->initiated_by)->orderBy('id')->first();
    }

    /**
     * Remboursement possible ? Seulement si l'expéditeur a été DÉBITÉ (écriture source → compte
     * d'attente, ou collecte payée chez le partenaire) et que le bénéficiaire n'a PAS été crédité
     * (ni versement réussi, ni versement encore en cours chez un partenaire).
     *
     * @return array{possible:bool, reason:?string, partner:string, to:string, amount:int, currency:string}
     */
    public function refundEligibility(Transaction $tx): array
    {
        $out = ['possible' => false, 'reason' => null, 'partner' => $this->canRefundToSource($tx) ? $this->partnerLabel($tx->source_rail) : 'FlashPay',
            'to' => $this->canRefundToSource($tx) ? (string) $tx->source_account : 'Wallet FlashPay', 'amount' => (int) $tx->amount + (int) $tx->fee, 'currency' => (string) $tx->currency];
        $outcome = (array) (($tx->meta ?? [])['outcome'] ?? []);
        $no = fn (string $why) => ['reason' => $why] + $out;

        if (in_array($tx->type, \App\Support\TransactionPresenter::REFUND_TYPES, true)) {
            return $no('Opération de remboursement.');
        }
        if ($tx->status === 'reversed' || ($outcome['refund']['done'] ?? false) === true) {
            return $no('Déjà remboursée.');
        }
        if ($tx->stage === 'awaiting_refund') {
            return $no('Remboursement déjà en cours chez ' . $this->partnerLabel($tx->source_rail) . '.');
        }
        if ($tx->status === 'successful' || ($outcome['credit']['done'] ?? false) === true) {
            return $no('Le bénéficiaire a été crédité : rien à rembourser.');
        }
        if (! $this->debitConfirmed($tx)) {
            return $no('Aucun débit confirmé de l\'expéditeur : rien à rembourser.');
        }
        if ($pending = $this->payoutInFlight($tx)) {
            return $no($pending);
        }
        if ($this->creditedDestination($tx)) {
            return $no('Le bénéficiaire a été crédité : rien à rembourser.');
        }
        return ['possible' => true] + $out;
    }

    /** Débit de l'expéditeur confirmé : écriture du payeur dans le grand livre, ou collecte payée chez le partenaire. */
    public function debitConfirmed(Transaction $tx): bool
    {
        if (\App\Models\LedgerEntry::where('transaction_id', $tx->id)->where('account', $this->payerAccount($tx))->where('type', 'debit')->exists()) {
            return true;
        }
        return $this->sourcePaidAtPartner($tx);
    }

    protected function payerAccount(Transaction $tx): string
    {
        return $tx->source_rail === 'wallet' ? "wallet:{$tx->source_wallet_id}" : "{$tx->source_rail}:{$tx->source_account}";
    }

    protected function sourcePaidAtPartner(Transaction $tx): bool
    {
        return match ($tx->source_rail) {
            'peex' => \App\Models\PeexRequest::where('transaction_id', $tx->id)->get()
                ->contains(fn ($r) => PeexConnector::legOf((string) $r->track_id) === 'C' && \App\Models\PeexRequest::normalize($r->status) === 'successful'),
            'digitwace' => \App\Models\DigitwaceRequest::where('transaction_id', $tx->id)->where('operation', 'payin')->where('status', 'successful')->exists(),
            'pawapay_momo', 'maishapay' => \App\Models\RemitRequest::where('transaction_id', $tx->id)->where('partner', $tx->source_rail)->where('method', 'deposit')->where('status', 'successful')->exists(),
            default => false,
        };
    }

    /** Versement au bénéficiaire encore en cours chez un partenaire : on attend son statut final (jamais de double paiement). */
    protected function payoutInFlight(Transaction $tx): ?string
    {
        if ($tx->status === 'processing' && $tx->stage === 'awaiting_destination') {
            return 'Versement au bénéficiaire encore en cours chez ' . $this->partnerLabel($tx->destination_rail) . ' : attendez son statut final (ou « Vérifier le statut »).';
        }
        $open = \App\Models\DigitwaceRequest::where('transaction_id', $tx->id)->where('operation', 'payout')->whereNull('finalized_at')->whereNotIn('status', ['failed'])->exists()
            || \App\Models\RemitRequest::where('transaction_id', $tx->id)->whereNotIn('method', ['deposit', 'refund'])->whereNull('finalized_at')->whereNotIn('status', ['failed'])->exists()
            || \App\Models\PeexRequest::where('transaction_id', $tx->id)->whereNull('finalized_at')->get()
                ->contains(fn ($r) => PeexConnector::legOf((string) $r->track_id) === 'D' && \App\Models\PeexRequest::normalize($r->status) !== 'failed');
        return $open ? 'Un versement au bénéficiaire est encore ouvert chez un partenaire : vérifiez son statut avant de rembourser.' : null;
    }

    /** Bénéficiaire crédité : écriture de crédit vers le compte / wallet du bénéficiaire, ou versement réussi chez un partenaire. */
    protected function creditedDestination(Transaction $tx): bool
    {
        $payer = $this->payerAccount($tx);
        $credited = \App\Models\LedgerEntry::where('transaction_id', $tx->id)->where('type', 'credit')
            ->where('account', 'not like', 'flashpay:%')->where('account', '!=', $payer)->exists();
        return $credited
            || \App\Models\DigitwaceRequest::where('transaction_id', $tx->id)->where('operation', 'payout')->where('status', 'successful')->exists()
            || \App\Models\RemitRequest::where('transaction_id', $tx->id)->whereNotIn('method', ['deposit', 'refund'])->where('status', 'successful')->exists()
            || \App\Models\PeexRequest::where('transaction_id', $tx->id)->get()
                ->contains(fn ($r) => PeexConnector::legOf((string) $r->track_id) === 'D' && \App\Models\PeexRequest::normalize($r->status) === 'successful');
    }

    /**
     * Remboursement lancé depuis la console (opération échouée après débit, ou orpheline) :
     * par le partenaire qui a collecté, vers le compte débité ; repli sur le wallet FlashPay.
     */
    public function refundDebited(Transaction $tx, string $reason): Transaction
    {
        return \Illuminate\Support\Facades\Cache::lock('refund:' . $tx->id, 30)->block(10, function () use ($tx, $reason) {
            $tx = $tx->fresh();
            $e = $this->refundEligibility($tx);
            if (! $e['possible']) {
                throw new \App\Exceptions\BusinessException('Remboursement impossible : ' . $e['reason'], 'refund_not_allowed', 422);
            }
            // Collecte payée chez le partenaire mais jamais comptabilisée (opération « orpheline »)
            if (! \App\Models\LedgerEntry::where('transaction_id', $tx->id)->where('account', $this->payerAccount($tx))->where('type', 'debit')->exists()) {
                $this->ledgerService->recordDoubleEntry($tx, $this->payerAccount($tx), 'flashpay:suspense', (int) $tx->amount, null, 'Collecte confirmée par le partenaire (réconciliation)');
                $this->recordFee($tx, $this->payerAccount($tx));
            }
            $tx->update(['status' => 'processing', 'stage' => 'awaiting_destination']);
            $this->noteOutcome($tx, ['credit' => ['done' => false, 'error' => mb_substr($reason, 0, 200), 'partner' => $this->partnerLabel($tx->destination_rail), 'account' => $tx->destination_account]]);
            return $this->failAndRefund($tx->fresh(), $reason);
        });
    }

    /** Le partenaire qui a collecté peut-il rembourser le numéro débité ? */
    protected function canRefundToSource(Transaction $tx): bool
    {
        if (! $tx->source_account || ! in_array($tx->source_rail, ['peex', 'digitwace', 'pawapay_momo', 'maishapay'], true)) {
            return false;
        }
        if ($tx->source_rail !== 'peex' && ! config('flashpay.failover.refund_to_source', true)) {
            return false;
        }
        try {
            return method_exists($this->refundConnector($tx), 'refund');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function refundConnector(Transaction $tx): object
    {
        return $tx->source_rail === 'peex' ? app(PeexConnector::class) : $this->connectorFor($tx->source_rail);
    }

    /** Lance le remboursement du payeur (montant + frais) chez le partenaire qui a collecté. */
    protected function startRefund(Transaction $tx, string $reason): Transaction
    {
        $reason = mb_substr($reason, 0, 200);
        $tx->update(['stage' => 'awaiting_refund', 'failure_reason' => $reason . ' · Remboursement en cours']);

        try {
            $result = $this->refundConnector($tx)->refund($tx->fresh());
        } catch (\Throwable $e) {
            $result = ['status' => 'failed', 'raw' => ['error' => $e->getMessage()]];
        }

        if ($result['status'] === 'pending') {
            return $tx->fresh();
        }
        if ($result['status'] === 'successful') {
            return $this->refunded($tx->fresh(), $reason);
        }

        return $this->refundFallback($tx, $reason, $this->reason($result));
    }

    public function onRefundConfirmed(Transaction $tx): Transaction
    {
        return $this->withLock($tx, 'awaiting_refund', fn (Transaction $tx) => $this->refunded($tx, (string) $tx->failure_reason));
    }

    public function onRefundFailed(Transaction $tx, string $reason): Transaction
    {
        return $this->withLock($tx, 'awaiting_refund', fn (Transaction $tx) => $this->refundFallback(
            $tx, str_replace(' · Remboursement en cours', '', (string) $tx->failure_reason), $reason));
    }

    /** Remboursement mobile money impossible : recrédit du wallet FlashPay du client, sinon remboursement manuel. */
    protected function refundFallback(Transaction $tx, string $reason, string $error): Transaction
    {
        if (config('flashpay.failover.refund_to_wallet', true) && ($done = $this->refundToWallet($tx, $reason, 'Remboursement sur ' . $tx->source_account . ' impossible : ' . $error))) {
            Log::warning('Remboursement mobile money impossible : wallet FlashPay recrédité', ['reference' => $tx->reference, 'error' => $error]);
            return $done;
        }
        Log::critical('Remboursement impossible : remboursement manuel requis', ['reference' => $tx->reference, 'error' => $error]);
        $this->noteOutcome($tx, ['refund' => ['done' => false, 'to' => 'manual', 'why' => mb_substr($error, 0, 200)]]);
        return $this->fail($tx, $reason . ' · Remboursement automatique impossible, remboursement manuel par le support');
    }

    /** Remboursement confirmé : écritures inverses et statut « reversed ». */
    protected function refunded(Transaction $tx, string $reason): Transaction
    {
        $refund = $this->recordRefund($tx, (string) $tx->source_rail, (string) $tx->source_account, null, $this->partnerLabel($tx->source_rail));
        $reason = str_replace(' · Remboursement en cours', '', $reason);
        $tx->update(['status' => 'reversed', 'stage' => null, 'failure_reason' => mb_substr($reason . ' · Remboursé sur ' . $tx->source_account . ' via ' . $this->partnerLabel($tx->source_rail), 0, 250)]);
        $this->noteOutcome($tx, ['refund' => ['done' => true, 'to' => (string) $tx->source_account, 'partner' => $this->partnerLabel($tx->source_rail),
            'reference' => $refund->reference, 'at' => now()->toIso8601String()]]);

        return $this->notified($tx->fresh());
    }

    /**
     * Opération « Remboursement » visible dans les journaux (client, console) : montant + frais
     * rendus au payeur, liée à l'opération d'origine (meta.refund_of). Les écritures du grand
     * livre du remboursement sont portées par cette opération (solde du wallet juste).
     */
    protected function recordRefund(Transaction $tx, string $toRail, ?string $toAccount, ?Wallet $wallet, string $via, ?string $why = null): Transaction
    {
        $m = $tx->meta ?? [];
        $total = (int) $tx->amount + (int) $tx->fee;
        $refund = Transaction::firstOrCreate(['reference' => $tx->reference . '-RMB'], [
            'type' => 'refund', 'scope' => $tx->scope ?: 'national',
            'source_rail' => 'treasury', 'destination_rail' => $toRail,
            'destination_account' => $toRail === 'wallet' ? null : $toAccount,
            'destination_wallet_id' => $wallet?->id,
            'amount' => $total, 'fee' => 0, 'currency' => $tx->currency,
            'status' => 'successful', 'completed_at' => now(), 'initiated_by' => $tx->initiated_by,
            'meta' => array_filter([
                'channel' => 'auto_refund', 'refund_of' => $tx->reference, 'refund_of_id' => $tx->id, 'refunded_via' => $via,
                'refund_reason' => $why ? mb_substr($why, 0, 200) : null,
                'sender_name' => 'FlashPay (remboursement)',
                'beneficiary_name' => $m['payer_verified_name'] ?? $m['payer_name'] ?? $m['sender_name'] ?? $tx->initiator?->full_name,
                'destination_country' => $m['source_country'] ?? null, 'destination_operator' => $m['source_operator'] ?? null,
                'refund_breakdown' => ['amount' => (int) $tx->amount, 'fee' => (int) $tx->fee],
            ], fn ($v) => $v !== null && $v !== ''),
        ]);
        if ($refund->wasRecentlyCreated) {
            $to = $toRail === 'wallet' ? "wallet:{$wallet?->id}" : "{$toRail}:{$toAccount}";
            $this->ledgerService->recordDoubleEntry($refund, 'flashpay:suspense', $to, (int) $tx->amount, null, "Remboursement {$tx->reference}");
            if ($tx->fee > 0) {
                $this->ledgerService->recordDoubleEntry($refund, 'flashpay:fees', $to, (int) $tx->fee, null, "Remboursement des frais {$tx->reference}");
            }
            $tx->forceFill(['meta' => array_merge($tx->fresh()->meta ?? [], ['refund_transaction' => $refund->reference])])->save();
        }
        return $refund;
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
