<?php

namespace App\Services;

use App\Exceptions\CashNetworkException;
use App\Models\Merchant;
use App\Models\SettlementAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\FeeService;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Règlement des marchands vers leurs comptes :
 *   mobile_money  versement PEEX vers n'importe quel opérateur couvert (MTN, Airtel, Orange…)
 *   wallet        transfert instantané vers un wallet FlashPay (ex. compte personnel)
 *   cash_pickup   bon de retrait : espèces chez un agent FlashPay
 *   bank          virement bancaire : wallet débité, virement exécuté par l'équipe FlashPay
 *                 (file « Règlements » de la console), remboursé en cas de rejet
 */
class SettlementService
{
    public const BANK_ACCOUNT = 'flashpay:bank_payouts';

    public function __construct(
        protected PeexCorridors $corridors,
        protected PeexFlowService $flows,
        protected CashNetworkService $cash,
        protected WalletService $wallets,
        protected LedgerService $ledger,
        protected FeeService $fees,
    ) {
    }

    /** Comptes du marchand ; crée le compte mobile money par défaut s'il n'en a aucun. */
    public function accounts(Merchant $m)
    {
        if (! $m->settlementAccounts()->exists() && ($m->settlement_phone || $m->user?->phone)) {
            try {
                $this->addAccount($m, ['type' => 'mobile_money', 'phone' => $m->settlement_phone ?: $m->user->phone, 'country' => $m->country, 'is_default' => true]);
            } catch (\Throwable) {
            }
        }
        return $m->settlementAccounts()->get();
    }

    public function addAccount(Merchant $m, array $d): SettlementAccount
    {
        $type = $d['type'] ?? '';
        $country = strtoupper($d['country'] ?? $m->country ?? $m->user?->wallet?->country ?? 'CG');
        $data = ['merchant_id' => $m->id, 'type' => $type, 'label' => $d['label'] ?? null, 'country' => $country];

        switch ($type) {
            case 'mobile_money':
            case 'wallet':
                if (empty($d['phone'])) {
                    throw new CashNetworkException('Numéro de téléphone requis.');
                }
                $r = $this->corridors->resolve($d['phone'], $country, $type === 'mobile_money');
                if ($type === 'mobile_money' && ! $r['payout']) {
                    throw new CashNetworkException("Les versements vers {$r['country_name']} ne sont pas encore ouverts.");
                }
                if ($type === 'wallet' && ! $this->flows->findUserByPhone($r['phone'])) {
                    throw new CashNetworkException('Aucun compte FlashPay avec ce numéro.');
                }
                $data = array_merge($data, ['phone' => ltrim($r['phone'], '+'), 'operator' => $type === 'wallet' ? 'FlashPay' : ($r['operator'] ?? null), 'country' => $r['country']]);
                break;
            case 'bank':
                foreach (['bank_name' => 'Nom de la banque', 'account_holder' => 'Titulaire', 'account_number' => 'Numéro de compte / IBAN'] as $k => $label) {
                    if (empty($d[$k])) {
                        throw new CashNetworkException("{$label} requis.");
                    }
                }
                $data += [
                    'bank_name' => $d['bank_name'],
                    'account_holder' => $d['account_holder'],
                    'account_number' => strtoupper(preg_replace('/\s+/', ' ', trim($d['account_number']))),
                    'swift' => ! empty($d['swift']) ? strtoupper(trim($d['swift'])) : null,
                ];
                break;
            case 'cash_pickup':
                $data += ['account_holder' => $d['account_holder'] ?? $m->user?->full_name];
                break;
            default:
                throw new CashNetworkException('Type de compte inconnu.');
        }

        return DB::transaction(function () use ($m, $data, $d) {
            $first = ! $m->settlementAccounts()->exists();
            $acc = SettlementAccount::create($data + ['is_default' => $first || ! empty($d['is_default'])]);
            if ($acc->is_default) {
                SettlementAccount::where('merchant_id', $m->id)->where('id', '<>', $acc->id)->update(['is_default' => false]);
            }
            return $acc;
        });
    }

    public function setDefault(SettlementAccount $acc): void
    {
        DB::transaction(function () use ($acc) {
            SettlementAccount::where('merchant_id', $acc->merchant_id)->update(['is_default' => false]);
            $acc->update(['is_default' => true]);
        });
    }

    /** @return array{transaction: Transaction, code?: string} */
    public function settle(User $user, SettlementAccount $acc, int $amount, bool $auto = false): array
    {
        $m = $user->merchant;
        if (! $m || $acc->merchant_id !== $m->id) {
            throw new CashNetworkException('Compte de règlement introuvable.', 404);
        }
        if ($m->validation_status !== 'approved') {
            throw new CashNetworkException('Compte marchand non validé.', 403);
        }
        if ($amount < 100) {
            throw new CashNetworkException('Montant minimum : 100.');
        }

        $meta = ['settlement_account_id' => $acc->id, 'settlement' => $auto ? 'auto' : 'manual', 'beneficiary_name' => $acc->account_holder ?: $user->full_name];

        switch ($acc->type) {
            case 'mobile_money':
                return ['transaction' => $this->tag($this->flows->withdraw($user, '+' . $acc->phone, $amount, ['destination_country' => $acc->country]), $meta)];
            case 'wallet':
                return ['transaction' => $this->tag($this->flows->transfer($user, 'wallet', null, '+' . $acc->phone, $amount, ['deliver_to' => 'auto', 'note' => 'Règlement marchand']), $meta)];
            case 'cash_pickup':
                $r = $this->cash->createVoucher($user, 'cash_pickup', $amount, $acc->country, ['beneficiary_name' => $meta['beneficiary_name']]);
                return ['transaction' => $this->tag($r['transaction'], $meta), 'code' => $r['code']];
            default:
                return ['transaction' => $this->bankTransfer($user, $acc, $amount, $meta)];
        }
    }

    public function bankTransfer(User $user, SettlementAccount|\App\Models\LinkedAccount $acc, int $amount, array $meta): Transaction
    {
        $wallet = $this->flows->walletOf($user);
        $fee = $this->fees->fee('bank_transfer', 'national', $amount);
        app(\App\Services\Compliance\ComplianceGuard::class)->assertChannel('bank');
        app(\App\Services\Compliance\FraudService::class)->evaluate($user, $amount, 'bank_transfer', $wallet->currency);
        app(\App\Services\Compliance\LimitService::class)->assertOutgoing($user, $wallet, $amount + $fee, 'national', 'bank_transfer');

        return DB::transaction(function () use ($user, $wallet, $acc, $amount, $fee, $meta) {
            $this->wallets->debit($wallet, $amount + $fee);
            $tx = Transaction::create([
                'reference' => 'FP-' . strtoupper(Str::random(12)),
                'type' => 'bank_transfer',
                'scope' => 'national',
                'source_rail' => 'wallet',
                'destination_rail' => 'bank',
                'source_wallet_id' => $wallet->id,
                'destination_account' => $acc->account_number,
                'amount' => $amount,
                'fee' => $fee,
                'currency' => $wallet->currency,
                'status' => 'processing',
                'stage' => 'awaiting_bank',
                'initiated_by' => $user->id,
                'meta' => $meta + [
                    'bank_name' => $acc->bank_name,
                    'account_holder' => $acc->account_holder,
                    'account_number' => $acc->account_number,
                    'swift' => $acc->swift ?? null,
                    'merchant_name' => $user->merchant?->business_name ?? $user->full_name,
                ],
            ]);
            $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", self::BANK_ACCOUNT, $amount, null, 'Virement bancaire à exécuter');
            if ($fee > 0) {
                $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", 'flashpay:fees', $fee, null, 'Frais de virement');
            }
            return $tx;
        });
    }

    /** L'équipe FlashPay a exécuté le virement (référence bancaire). */
    public function completeBank(Transaction $tx, string $bankRef): Transaction
    {
        return DB::transaction(function () use ($tx, $bankRef) {
            $t = Transaction::whereKey($tx->id)->lockForUpdate()->first();
            if ($t->type !== 'bank_transfer' || $t->status !== 'processing') {
                throw new CashNetworkException('Ce virement a déjà été traité.');
            }
            $this->ledger->recordDoubleEntry($t, self::BANK_ACCOUNT, 'partner:bank', $t->amount, null, "Virement exécuté ({$bankRef})");
            $t->update(['status' => 'successful', 'stage' => null, 'completed_at' => now(), 'destination_external_ref' => $bankRef]);
            \App\Support\Audit::log('bank_transfer.complete', $t, ['bank_ref' => $bankRef]);
            app(\App\Services\Notifications\TransactionNotifier::class)->handle($t->fresh());
            return $t->fresh();
        });
    }

    /** Virement impossible (compte erroné…) : wallet du marchand remboursé. */
    public function rejectBank(Transaction $tx, string $reason): Transaction
    {
        return DB::transaction(function () use ($tx, $reason) {
            $t = Transaction::whereKey($tx->id)->lockForUpdate()->first();
            if ($t->type !== 'bank_transfer' || $t->status !== 'processing') {
                throw new CashNetworkException('Ce virement a déjà été traité.');
            }
            $wallet = \App\Models\Wallet::findOrFail($t->source_wallet_id);
            $this->wallets->credit($wallet, $t->amount + $t->fee);
            $this->ledger->recordDoubleEntry($t, self::BANK_ACCOUNT, "wallet:{$wallet->id}", $t->amount, null, 'Virement rejeté — remboursement');
            if ($t->fee > 0) {
                $this->ledger->recordDoubleEntry($t, 'flashpay:fees', "wallet:{$wallet->id}", $t->fee, null, 'Remboursement des frais');
            }
            $t->update(['status' => 'reversed', 'stage' => null, 'failure_reason' => mb_substr('Virement rejeté : ' . $reason, 0, 250)]);
            \App\Support\Audit::log('bank_transfer.reject', $t, ['reason' => $reason]);
            app(\App\Services\Notifications\TransactionNotifier::class)->handle($t->fresh());
            return $t->fresh();
        });
    }

    /** Règlement automatique : solde au-delà du minimum conservé -> compte par défaut. */
    public function runAuto(): array
    {
        $done = [];
        Merchant::where('validation_status', 'approved')->whereIn('auto_settlement', ['daily', 'weekly'])->with('user.wallet')->each(function (Merchant $m) use (&$done) {
            $last = $m->last_auto_settlement_at;
            $due = ! $last
                || ($m->auto_settlement === 'daily' && $last->lt(now()->startOfDay()))
                || ($m->auto_settlement === 'weekly' && $last->lt(now()->subDays(7)));
            $acc = $m->settlementAccounts()->where('is_default', true)->first();
            $wallet = $m->user?->wallet;
            if (! $due || ! $acc || ! $wallet) {
                return;
            }
            $available = (int) $wallet->balance - (int) $m->auto_settlement_min;
            // Frais de l'opération de règlement (grille tarifaire) déduits pour ne pas entamer le minimum conservé
            $feeOp = ['mobile_money' => 'withdrawal', 'wallet' => 'p2p', 'cash_pickup' => 'cash_pickup', 'bank' => 'bank_transfer'][$acc->type] ?? 'withdrawal';
            // Plus grand montant tel que montant + frais(montant) <= disponible (recherche dichotomique)
            [$lo, $hi] = [0, max(0, $available)];
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi + 1, 2);
                $mid + $this->fees->fee($feeOp, 'national', $mid) <= $available ? $lo = $mid : $hi = $mid - 1;
            }
            $amount = $lo;
            if ($amount < 1000) {
                return;
            }
            try {
                $r = $this->settle($m->user, $acc, $amount, true);
                $m->forceFill(['last_auto_settlement_at' => now()])->save();
                $done[] = ['merchant' => $m->business_name, 'amount' => $amount, 'reference' => $r['transaction']->reference];
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Règlement automatique échoué', ['merchant' => $m->id, 'error' => $e->getMessage()]);
            }
        });
        return $done;
    }

    /** Derniers règlements du marchand (toutes méthodes). */
    public function history(User $user, int $limit = 30)
    {
        $wid = $user->wallet?->id;
        return Transaction::where('source_wallet_id', $wid)
            ->whereIn('type', ['withdrawal', 'p2p', 'cash_pickup', 'bank_transfer'])
            ->latest()->limit($limit)->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'method' => match ($t->type) { 'withdrawal' => 'mobile_money', 'p2p' => 'wallet', 'cash_pickup' => 'cash_pickup', default => 'bank' },
                'label' => match ($t->type) { 'withdrawal' => 'Mobile money', 'p2p' => 'Wallet FlashPay', 'cash_pickup' => 'Retrait cash', default => 'Virement bancaire' },
                'to' => $t->destination_account ?: ($t->meta['beneficiary_name'] ?? null),
                'amount' => $t->amount,
                'fee' => $t->fee,
                'currency' => $t->currency,
                'status' => $t->status,
                'stage' => $t->stage,
                'auto' => ($t->meta['settlement'] ?? null) === 'auto',
                'created_at' => $t->created_at,
            ]);
    }

    protected function tag(Transaction $tx, array $meta): Transaction
    {
        $tx->update(['meta' => ($tx->meta ?? []) + $meta]);
        return $tx->fresh();
    }
}
