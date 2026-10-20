<?php

namespace App\Services\Bills;

use App\Exceptions\BusinessException;
use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\Maishapay\MaishapayClient;
use App\Services\Payments\FeeService;
use App\Services\Remit\RemitException;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Services payés depuis le wallet FlashPay, exécutés par MaishaPay :
 *   airtime  crédit téléphonique (Vodacom, Airtel, Orange, MTN, Africell)
 *   paytv    abonnement TV (Canal+, DStv, StarTimes)
 *   utility  factures (eau REGIDESO, électricité SNEL, internet)
 *
 * Le wallet est débité (montant + frais) vers le compte d'attente ; succès confirmé par
 * l'API MaishaPay → opération réussie ; échec certain → montant ET frais recrédités sur le
 * wallet (opération « Remboursement » distincte, visible au journal).
 */
class BillPaymentService
{
    public const SERVICES = [
        'airtime' => ['label' => 'Crédit téléphonique', 'type' => 'airtime', 'fee_operation' => 'airtime',
            'providers' => ['vodacom' => 'Vodacom', 'airtel' => 'Airtel', 'orange' => 'Orange', 'mtn' => 'MTN', 'africell' => 'Africell']],
        'paytv' => ['label' => 'Abonnement TV', 'type' => 'tv_subscription', 'fee_operation' => 'paytv',
            'providers' => ['canal_plus' => 'Canal+', 'dstv' => 'DStv', 'startimes' => 'StarTimes']],
        'utility' => ['label' => 'Factures', 'type' => 'bill_payment', 'fee_operation' => 'utility',
            'providers' => ['regideso' => 'REGIDESO (eau)', 'snel' => 'SNEL (électricité)', 'internet' => 'Internet']],
    ];

    public function __construct(
        protected MaishapayClient $client,
        protected WalletService $wallets,
        protected LedgerService $ledger,
        protected FeeService $fees,
    ) {
    }

    public function available(): bool
    {
        return $this->client->enabled() && (bool) config('flashpay.bills.enabled', true);
    }

    /** Catalogue pour l'app : services, fournisseurs, champs demandés. */
    public function catalog(?User $user = null): array
    {
        $wallet = $user ? Wallet::where('user_id', $user->id)->orderBy('id')->first() : null;
        return [
            'available' => $this->available(),
            'message' => $this->available() ? null : 'Services bientôt disponibles.',
            'currency' => $wallet?->currency ?? 'XAF',
            'balance' => $wallet ? (int) $wallet->balance : null,
            'services' => collect(self::SERVICES)->map(fn ($s, $k) => [
                'key' => $k, 'label' => $s['label'],
                'providers' => collect($s['providers'])->map(fn ($l, $code) => ['code' => $code, 'label' => $l])->values(),
                'fields' => match ($k) {
                    'airtime' => [['key' => 'phone', 'label' => 'Numéro à recharger', 'type' => 'phone']],
                    'paytv' => [['key' => 'decoder_number', 'label' => 'Numéro du décodeur / carte', 'type' => 'text'],
                        ['key' => 'package', 'label' => 'Formule (ex. EVASION_PLUS)', 'type' => 'text'],
                        ['key' => 'duration_months', 'label' => 'Durée (mois)', 'type' => 'number']],
                    'utility' => [['key' => 'account_number', 'label' => 'N° de compteur / contrat', 'type' => 'text']],
                },
            ])->values(),
        ];
    }

    public function quote(User $user, string $service, int $amount): array
    {
        $s = self::SERVICES[$service] ?? throw new BusinessException('Service inconnu.');
        $wallet = $this->walletOf($user);
        $fee = $this->fees->fee($s['fee_operation'], 'national', $amount);
        return ['service' => $service, 'label' => $s['label'], 'amount' => $amount, 'fee' => $fee, 'total' => $amount + $fee,
            'currency' => $wallet->currency, 'balance' => (int) $wallet->balance, 'enough' => (int) $wallet->balance >= $amount + $fee, 'available' => $this->available()];
    }

    /** Paiement depuis le wallet. @param array{provider:string, phone?:string, decoder_number?:string, package?:string, duration_months?:int, account_number?:string} $d */
    public function pay(User $user, string $service, int $amount, array $d): Transaction
    {
        $s = self::SERVICES[$service] ?? throw new BusinessException('Service inconnu.');
        if (! $this->available()) {
            throw new BusinessException('Ce service n\'est pas encore disponible.', 'service_unavailable');
        }
        if (! isset($s['providers'][$d['provider'] ?? ''])) {
            throw new BusinessException('Fournisseur inconnu pour ce service.');
        }
        $target = match ($service) {
            'airtime' => trim((string) ($d['phone'] ?? '')),
            'paytv' => trim((string) ($d['decoder_number'] ?? '')),
            'utility' => trim((string) ($d['account_number'] ?? '')),
        };
        if ($target === '') {
            throw new BusinessException($service === 'airtime' ? 'Indiquez le numéro à recharger.' : ($service === 'paytv' ? 'Indiquez le numéro du décodeur.' : 'Indiquez le numéro de compteur ou de contrat.'));
        }
        $wallet = $this->walletOf($user);
        $q = $this->quote($user, $service, $amount);
        if (! $q['enough']) {
            throw new BusinessException('Solde insuffisant (' . number_format((int) $wallet->balance, 0, ',', ' ') . " {$wallet->currency}).", 'insufficient_funds');
        }

        $reference = 'FP-' . Str::upper(Str::random(12));
        $tx = DB::transaction(function () use ($user, $wallet, $service, $s, $amount, $q, $d, $target, $reference) {
            $tx = Transaction::create([
                'reference' => $reference, 'type' => $s['type'], 'scope' => 'national',
                'source_rail' => 'wallet', 'source_wallet_id' => $wallet->id,
                'destination_rail' => 'maishapay', 'destination_account' => $target,
                'amount' => $amount, 'fee' => $q['fee'], 'currency' => $wallet->currency,
                'status' => 'processing', 'stage' => 'awaiting_destination', 'initiated_by' => $user->id,
                'meta' => array_filter([
                    'channel' => 'bill_' . $service, 'service' => $service, 'service_label' => $s['label'],
                    'provider' => $d['provider'], 'provider_label' => $s['providers'][$d['provider']],
                    'beneficiary_name' => $s['providers'][$d['provider']], 'beneficiary_phone' => $service === 'airtime' ? $target : null,
                    'decoder_number' => $d['decoder_number'] ?? null, 'package' => $d['package'] ?? null,
                    'duration_months' => $d['duration_months'] ?? null, 'account_number' => $d['account_number'] ?? null,
                    'sender_name' => $user->full_name,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
            $this->wallets->debit($wallet, $amount + $q['fee']);
            $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", 'flashpay:suspense', $amount);
            if ($q['fee'] > 0) {
                $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", 'flashpay:fees', $q['fee'], null, 'Frais FlashPay');
            }
            return $tx;
        });

        $req = RemitRequest::create(['transaction_id' => $tx->id, 'partner' => 'maishapay', 'reference' => $reference, 'method' => $service, 'status' => 'new']);
        try {
            $r = $this->client->service($service, $reference, $this->fields($service, $d, $amount, $tx->currency, $user));
            $req->update(['partner_ref' => $r['id'] ?: $reference, 'status' => $r['status'] === 'successful' ? 'successful' : 'pending',
                'raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now()]);
            $tx->forceFill(['meta' => array_merge($tx->fresh()->meta ?? [], ['maishapay_service' => ['sent' => $r['sent'], 'response' => $r['raw'], 'at' => now()->toIso8601String()]])])->save();
            if ($r['status'] === 'successful') {
                $req->update(['finalized_at' => now()]);
                return $this->finalize($tx->fresh(), true, null);
            }
            return $tx->fresh();
        } catch (RemitException $e) {
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'partner_ref' => $definitive ? null : $reference,
                'message' => mb_substr($e->getMessage(), 0, 250), 'last_response' => $e->response ?: null, 'last_checked_at' => now(),
                'finalized_at' => $definitive ? now() : null]);
            Log::warning('MaishaPay : service ' . $service . ' ' . ($definitive ? 'refusé' : 'incertain'), ['reference' => $reference, 'error' => $e->getMessage()]);
            return $definitive ? $this->finalize($tx->fresh(), false, $e->getMessage()) : $tx->fresh();
        } catch (\Throwable $e) {
            $req->update(['status' => 'pending', 'partner_ref' => $reference, 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return $tx->fresh();
        }
    }

    protected function fields(string $service, array $d, int $amount, string $currency, User $user): array
    {
        return match ($service) {
            'airtime' => ['phone' => '+' . preg_replace('/\D/', '', (string) $d['phone']), 'network' => $d['provider'], 'amount' => $amount, 'currency' => $currency],
            'paytv' => array_filter(['provider' => $d['provider'], 'decoder_number' => $d['decoder_number'], 'package' => $d['package'] ?? null,
                'duration_months' => (int) ($d['duration_months'] ?? 1) ?: 1, 'amount' => $amount, 'currency' => $currency,
                'phone' => '+' . preg_replace('/\D/', '', (string) $user->phone), 'payment_method' => config('flashpay.bills.payment_method', 'balance')]),
            'utility' => ['provider' => $d['provider'], 'account_number' => $d['account_number'], 'amount' => $amount, 'currency' => $currency,
                'phone' => '+' . preg_replace('/\D/', '', (string) $user->phone)],
        };
    }

    /** Résultat confirmé par MaishaPay (envoi, notification revérifiée ou remit:sync). Idempotent. */
    public function finalize(Transaction $tx, bool $ok, ?string $reason): Transaction
    {
        return DB::transaction(function () use ($tx, $ok, $reason) {
            $tx = Transaction::whereKey($tx->id)->lockForUpdate()->first();
            if ($tx->status !== 'processing') {
                return $tx;
            }
            if ($ok) {
                $this->ledger->recordDoubleEntry($tx, 'flashpay:suspense', 'maishapay:services', (int) $tx->amount, null, ($tx->meta['service_label'] ?? 'Service') . ' payé via MaishaPay');
                $tx->update(['status' => 'successful', 'stage' => null, 'completed_at' => now()]);
                return $tx->fresh();
            }
            // Échec certain : montant ET frais rendus sur le wallet (opération « Remboursement » distincte)
            $wallet = Wallet::find($tx->source_wallet_id);
            $this->wallets->credit($wallet, (int) $tx->amount + (int) $tx->fee);
            $refund = Transaction::create([
                'reference' => $tx->reference . '-RMB', 'type' => 'refund', 'scope' => 'national',
                'source_rail' => 'treasury', 'destination_rail' => 'wallet', 'destination_wallet_id' => $wallet->id,
                'amount' => (int) $tx->amount + (int) $tx->fee, 'fee' => 0, 'currency' => $tx->currency,
                'status' => 'successful', 'completed_at' => now(), 'initiated_by' => $tx->initiated_by,
                'meta' => ['channel' => 'auto_refund', 'refund_of' => $tx->reference, 'refund_of_id' => $tx->id, 'refunded_via' => 'FlashPay',
                    'sender_name' => 'FlashPay (remboursement)', 'refund_breakdown' => ['amount' => (int) $tx->amount, 'fee' => (int) $tx->fee]],
            ]);
            $this->ledger->recordDoubleEntry($refund, 'flashpay:suspense', "wallet:{$wallet->id}", (int) $tx->amount, null, "Remboursement {$tx->reference}");
            if ($tx->fee > 0) {
                $this->ledger->recordDoubleEntry($refund, 'flashpay:fees', "wallet:{$wallet->id}", (int) $tx->fee, null, "Remboursement des frais {$tx->reference}");
            }
            $meta = $tx->meta ?? [];
            $meta['refund_transaction'] = $refund->reference;
            $meta['outcome'] = ['debit' => ['done' => true], 'credit' => ['done' => false, 'error' => mb_substr((string) $reason, 0, 200), 'partner' => 'MaishaPay'],
                'refund' => ['done' => true, 'to' => 'wallet', 'reference' => $refund->reference, 'at' => now()->toIso8601String()]];
            $tx->update(['status' => 'reversed', 'stage' => null, 'meta' => $meta,
                'failure_reason' => mb_substr(($reason ?: 'Service refusé par MaishaPay') . ' · Montant et frais remboursés sur votre wallet FlashPay', 0, 250)]);
            return $tx->fresh();
        });
    }

    protected function walletOf(User $user): Wallet
    {
        return Wallet::where('user_id', $user->id)->orderBy('id')->first() ?? throw new BusinessException('Wallet FlashPay introuvable.');
    }
}
