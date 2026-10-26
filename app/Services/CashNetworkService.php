<?php

namespace App\Services;

use App\Exceptions\CashNetworkException;
use App\Models\PayCode;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalVoucher;
use App\Services\Payments\FeeService;
use App\Services\Payments\PaymentMethodsService;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexFlowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Réseau cash & QR OfficePay (sans passerelle externe) :
 *
 *  CODE DE PAIEMENT (style Alipay / WeChat Pay)
 *    issuePayCode()   le client affiche un QR / code à 18 chiffres, valable 2 min, usage unique
 *    chargeWithCode() le marchand scanne le code -> wallet client débité -> marchand crédité
 *    agentCashIn()    l'agent scanne le code (ou saisit le numéro) -> dépôt d'espèces sur le wallet
 *
 *  BONS DE RETRAIT (cash pickup chez un agent, GAB d'une banque partenaire)
 *    createVoucher()  wallet débité (montant + frais) -> compte d'attente flashpay:vouchers
 *    redeemVoucher()  l'agent remet les espèces (ou le GAB distribue) -> bon consommé
 *    cancelVoucher()  / expireVouchers() -> remboursement intégral du wallet
 */
class CashNetworkService
{
    public const VOUCHERS_ACCOUNT = 'flashpay:vouchers';

    public function __construct(
        protected WalletService $wallets,
        protected LedgerService $ledger,
        protected FeeService $fees,
        protected PeexCorridors $corridors,
        protected PeexFlowService $flows,
        protected PaymentMethodsService $methods,
        protected SwitchService $switch,
    ) {
    }

    // =================================================== Code de paiement client

    /** @return array{code:string, qr:string, expires_at:\Illuminate\Support\Carbon} */
    public function issuePayCode(User $user): array
    {
        PayCode::where('user_id', $user->id)->where('status', 'active')->update(['status' => 'revoked']);

        do {
            $code = '88' . $this->digits(16);
        } while (PayCode::where('code_hash', PayCode::hash($code))->exists());

        $pc = PayCode::create([
            'user_id' => $user->id,
            'code_hash' => PayCode::hash($code),
            'status' => 'active',
            'expires_at' => now()->addSeconds(config('payment_methods.pay_code_ttl_seconds', 120)),
        ]);

        return ['code' => $code, 'qr' => "flashpay://code?c={$code}", 'expires_at' => $pc->expires_at];
    }

    /** Consomme un code (usage unique) et renvoie le client qui l'a présenté. */
    public function consumePayCode(string $code, User $by): array
    {
        return DB::transaction(function () use ($code, $by) {
            $pc = PayCode::where('code_hash', PayCode::hash($code))->lockForUpdate()->first();

            if (! $pc || $pc->status !== 'active' || $pc->expires_at->isPast()) {
                throw new CashNetworkException('Code de paiement invalide ou expiré. Demandez au client de rafraîchir son code.');
            }
            if ($pc->user_id === $by->id) {
                throw new CashNetworkException('Vous ne pouvez pas utiliser votre propre code.');
            }

            $pc->update(['status' => 'used', 'used_at' => now(), 'used_by' => $by->id]);

            return [$pc, $pc->user];
        });
    }

    /** Le marchand encaisse en scannant le code de paiement du client. */
    public function chargeWithCode(User $merchantUser, string $code, int $amount): Transaction
    {
        $merchant = $merchantUser->merchant;
        if (! $merchant) {
            throw new CashNetworkException('Compte marchand introuvable.', 403);
        }
        if ($amount > config('payment_methods.pay_code_max_amount', 200000)) {
            throw new CashNetworkException('Montant supérieur au plafond autorisé par code de paiement.');
        }

        [$pc, $payer] = $this->consumePayCode($code, $merchantUser);

        $tx = $this->flows->payMerchant($merchantUser, $payer, $merchant, 'wallet', null, $amount, 'pay_code');
        $pc->update(['transaction_id' => $tx->id]);

        return $tx;
    }

    /**
     * Dépôt d'espèces chez un agent. Le client est identifié par son code de
     * paiement (QR scanné par l'agent = consentement du client) ou par son numéro.
     */
    public function agentCashIn(User $agentUser, ?string $clientCode, ?string $clientPhone, int $amount): Transaction
    {
        $agent = $agentUser->agent;
        if (! $agent || $agent->validation_status !== 'approved') {
            throw new CashNetworkException('Agent non validé.', 403);
        }

        $pc = null;
        if ($clientCode) {
            [$pc, $client] = $this->consumePayCode($clientCode, $agentUser);
        } else {
            $client = $this->flows->findUserByPhone($this->corridors->resolve((string) $clientPhone)['phone'])
                ?? throw new CashNetworkException('Aucun compte OfficePay pour ce numéro.');
        }

        $agentWallet = $agentUser->wallet ?? throw new CashNetworkException('Wallet agent introuvable.');
        $clientWallet = $this->flows->walletOf($client);
        if ($agentWallet->currency !== $clientWallet->currency) {
            throw new CashNetworkException('Dépôt impossible : devise du client différente de celle de l\'agent.');
        }

        $tx = $this->switch->process([
            'type' => 'cash_in',
            'scope' => 'national',
            'source_rail' => 'wallet', // float électronique de l'agent
            'source_wallet_id' => $agentWallet->id,
            'destination_rail' => 'wallet',
            'destination_wallet_id' => $clientWallet->id,
            'amount' => $amount,
            'currency' => $clientWallet->currency,
            'initiated_by' => $agentUser->id,
            'meta' => [
                'channel' => 'agent',
                'method' => $pc ? 'pay_code' : 'phone',
                'agent_id' => $agent->id,
                'agent_name' => $agentUser->full_name,
                'client_name' => $client->full_name,
            ],
        ]);

        $pc?->update(['transaction_id' => $tx->id]);

        // Commission agent sur la recharge (§3.1.7)
        if ($tx->status === 'successful') {
            $c = app(\App\Services\Agent\CommissionService::class);
            $c->pay($tx, $agentWallet->fresh(), $c->compute('cash_in', $amount, (int) $tx->fee), 'cash_in');
            $tx = $tx->fresh();
        }

        return $tx;
    }

    // =================================================== Bons de retrait

    /**
     * @param string $channel cash_pickup | atm
     * @return array{voucher: WithdrawalVoucher, code: string, transaction: Transaction}
     */
    public function createVoucher(User $user, string $channel, int $amount, string $country, array $opt = []): array
    {
        $country = strtoupper($country);
        if (! $this->methods->isAvailable($country, 'withdraw', $channel)) {
            throw new CashNetworkException($channel === 'atm'
                ? 'Le retrait au GAB n\'est pas encore disponible dans ce pays.'
                : 'Le retrait cash n\'est pas encore disponible dans ce pays.');
        }
        if ($amount > config('payment_methods.voucher_max_amount', 500000)) {
            throw new CashNetworkException('Montant supérieur au plafond de retrait.');
        }

        $wallet = $this->flows->walletOf($user);
        $dest = $this->corridors->country($country);
        if ($dest['currency'] !== $wallet->currency) {
            throw new CashNetworkException("Retrait en {$dest['currency']} pas encore disponible depuis un wallet en {$wallet->currency}.");
        }

        $scope = $this->corridors->scope($wallet->country ?: $country, $country);
        $fee = $this->fees->fee($channel, $scope, $amount);
        $code = $this->uniqueVoucherCode($channel);

        // Canal, anti-fraude et plafonds KYC (§12)
        $guard = app(\App\Services\Compliance\ComplianceGuard::class);
        $guard->assertChannel('agents');
        app(\App\Services\Compliance\FraudService::class)->evaluate($user, $amount, 'cash_pickup', $wallet->currency);
        app(\App\Services\Compliance\LimitService::class)->assertOutgoing($user, $wallet, $amount + $fee, $scope, 'cash_pickup');

        return DB::transaction(function () use ($user, $wallet, $channel, $amount, $fee, $country, $scope, $code, $opt) {
            $this->wallets->debit($wallet, $amount + $fee);

            $tx = Transaction::create([
                'reference' => 'FP-' . strtoupper(Str::random(12)),
                'type' => $channel === 'atm' ? 'atm_withdrawal' : 'cash_pickup',
                'scope' => $scope,
                'source_rail' => 'wallet',
                'destination_rail' => $channel === 'atm' ? 'atm' : 'cash',
                'source_wallet_id' => $wallet->id,
                'amount' => $amount,
                'fee' => $fee,
                'currency' => $wallet->currency,
                'status' => 'processing',
                'stage' => 'awaiting_pickup',
                'initiated_by' => $user->id,
                'meta' => array_filter([
                    'channel' => $channel,
                    'destination_country' => $country,
                    'beneficiary_name' => $opt['beneficiary_name'] ?? $user->full_name,
                    'beneficiary_phone' => $opt['beneficiary_phone'] ?? null,
                ]),
            ]);

            $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", self::VOUCHERS_ACCOUNT, $amount, null, 'Bon de retrait');
            if ($fee > 0) {
                $this->ledger->recordDoubleEntry($tx, "wallet:{$wallet->id}", 'flashpay:fees', $fee, null, 'Frais de retrait');
            }

            $voucher = WithdrawalVoucher::create([
                'transaction_id' => $tx->id,
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'channel' => $channel,
                'country' => $country,
                'code_hash' => WithdrawalVoucher::hash($code),
                'code_encrypted' => $code,
                'amount' => $amount,
                'fee' => $fee,
                'currency' => $wallet->currency,
                'beneficiary_name' => $opt['beneficiary_name'] ?? $user->full_name,
                'beneficiary_phone' => $opt['beneficiary_phone'] ?? null,
                'status' => 'pending',
                'expires_at' => now()->addHours(config('payment_methods.voucher_ttl_hours', 72)),
            ]);

            app(\App\Services\Notifications\NotificationService::class)->toUser($user, 'voucher_created', 'Code de retrait généré', 'Montant ' . number_format($amount, 0, ',', ' ') . " {$wallet->currency}. Présentez le code à " . ($channel === 'atm' ? 'un GAB partenaire' : 'un agent OfficePay') . ' avant le ' . $voucher->expires_at->format('d/m H:i') . '.', ['data' => ['voucher_id' => $voucher->id], 'sms' => 'Code de retrait OfficePay genere: ' . number_format($amount, 0, ',', ' ') . " {$wallet->currency}. Ne communiquez le code qu'a l'agent."]);

            return ['voucher' => $voucher, 'code' => $code, 'transaction' => $tx->fresh()];
        });
    }

    /** Aperçu pour l'agent avant de remettre les espèces. */
    public function findPendingVoucher(string $code, string $channel): WithdrawalVoucher
    {
        $v = WithdrawalVoucher::where('code_hash', WithdrawalVoucher::hash($code))->first();
        if (! $v || $v->channel !== $channel || ! $v->isRedeemable()) {
            throw new CashNetworkException('Code de retrait invalide, déjà utilisé ou expiré.');
        }
        return $v;
    }

    /**
     * Consomme un bon. $agentUser pour un cash pickup ; $partnerRef pour un GAB.
     */
    public function redeemVoucher(string $code, string $channel, ?User $agentUser = null, ?string $partnerRef = null): WithdrawalVoucher
    {
        return DB::transaction(function () use ($code, $channel, $agentUser, $partnerRef) {
            $v = WithdrawalVoucher::where('code_hash', WithdrawalVoucher::hash($code))->lockForUpdate()->first();
            if (! $v || $v->channel !== $channel || ! $v->isRedeemable()) {
                throw new CashNetworkException('Code de retrait invalide, déjà utilisé ou expiré.');
            }
            $tx = $v->transaction;

            if ($channel === 'cash_pickup') {
                $agent = $agentUser?->agent;
                if (! $agent || $agent->validation_status !== 'approved') {
                    throw new CashNetworkException('Agent non validé.', 403);
                }
                $agentWallet = $agentUser->wallet ?? throw new CashNetworkException('Wallet agent introuvable.');
                if ($agentWallet->country && $agentWallet->country !== $v->country) {
                    throw new CashNetworkException('Ce bon doit être retiré dans un autre pays.');
                }
                // Sécurité : l'expéditeur a confirmé ce retrait dans son application
                $this->assertSenderApproved($v, $agentUser);

                // L'agent a remis les espèces : son float électronique est reconstitué
                $this->wallets->credit($agentWallet, $v->amount);
                $this->ledger->recordDoubleEntry($tx, self::VOUCHERS_ACCOUNT, "wallet:{$agentWallet->id}", $v->amount, null, 'Retrait cash — espèces remises');

                $commissions = app(\App\Services\Agent\CommissionService::class);
                $commission = $commissions->compute('cash_out', $v->amount, $v->fee);
                if ($commission > 0) {
                    $this->wallets->credit($agentWallet, $commission);
                    $this->ledger->recordDoubleEntry($tx, 'flashpay:fees', "wallet:{$agentWallet->id}", $commission, null, 'Commission agent');
                }

                $tx->update([
                    'destination_wallet_id' => $agentWallet->id,
                    'destination_account' => $agentUser->phone,
                    'meta' => ($tx->meta ?? []) + ['agent_id' => $agent->id, 'agent_name' => $agentUser->full_name, 'agent_commission' => $commission],
                ]);
            } else {
                // GAB : la banque partenaire a distribué les billets -> dette envers elle (règlement périodique)
                $this->ledger->recordDoubleEntry($tx, self::VOUCHERS_ACCOUNT, 'partner:atm', $v->amount, null, 'Retrait GAB');
                $tx->update(['destination_external_ref' => $partnerRef]);
            }

            $v->update(['status' => 'redeemed', 'redeemed_at' => now(), 'redeemed_by' => $agentUser?->id, 'partner_ref' => $partnerRef]);
            $tx->update(['status' => 'successful', 'stage' => null, 'completed_at' => now()]);
            app(\App\Services\Notifications\TransactionNotifier::class)->handle($tx->fresh());

            return $v->fresh();
        });
    }

    public function cancelVoucher(WithdrawalVoucher $voucher, string $status = 'cancelled'): WithdrawalVoucher
    {
        return DB::transaction(function () use ($voucher, $status) {
            $v = WithdrawalVoucher::whereKey($voucher->id)->lockForUpdate()->first();
            if ($v->status !== 'pending') {
                throw new CashNetworkException('Ce bon ne peut plus être annulé.');
            }
            $tx = $v->transaction;
            $wallet = Wallet::findOrFail($v->wallet_id);

            $this->wallets->credit($wallet, $v->amount + $v->fee);
            $this->ledger->recordDoubleEntry($tx, self::VOUCHERS_ACCOUNT, "wallet:{$wallet->id}", $v->amount, null, 'Remboursement bon de retrait');
            if ($v->fee > 0) {
                $this->ledger->recordDoubleEntry($tx, 'flashpay:fees', "wallet:{$wallet->id}", $v->fee, null, 'Remboursement des frais');
            }

            $v->update(['status' => $status]);
            $tx->update([
                'status' => 'reversed',
                'stage' => null,
                'failure_reason' => $status === 'expired' ? 'Bon de retrait expiré — wallet remboursé' : 'Bon de retrait annulé — wallet remboursé',
            ]);

            return $v->fresh();
        });
    }

    /** Rembourse les bons expirés (planifié toutes les 10 minutes). */
    public function expireVouchers(): int
    {
        $n = 0;
        WithdrawalVoucher::where('status', 'pending')->where('expires_at', '<', now())->each(function (WithdrawalVoucher $v) use (&$n) {
            try {
                $this->cancelVoucher($v, 'expired');
                $n++;
            } catch (CashNetworkException) {
                // déjà traité entre-temps
            }
        });
        return $n;
    }

    // =================================================== Confirmation de l'expéditeur (retrait cash)

    /** Minutes pendant lesquelles une demande puis une confirmation restent valables. */
    public const CONFIRM_TTL_MINUTES = 15;

    public static function senderConfirmationRequired(): bool
    {
        return (bool) config('security.cash_out_sender_confirmation', true);
    }

    /**
     * L'agent a saisi le code. Retrait pour une autre personne : il saisit aussi le nom et le
     * téléphone du bénéficiaire (lus sur sa pièce), vérifiés ici. L'expéditeur reçoit ensuite
     * une demande de confirmation dans l'application ; sans son accord, pas de paiement.
     */
    public function requestSenderConfirmation(string $code, User $agentUser, ?string $name = null, ?string $phone = null): WithdrawalVoucher
    {
        $v = DB::transaction(function () use ($code, $agentUser, $name, $phone) {
            $v = WithdrawalVoucher::where('code_hash', WithdrawalVoucher::hash($code))->lockForUpdate()->first();
            if (! $v || $v->channel !== 'cash_pickup' || ! $v->isRedeemable()) {
                throw new CashNetworkException('Code de retrait invalide, déjà utilisé ou expiré.');
            }
            $agent = $agentUser->agent;
            if (! $agent || $agent->validation_status !== 'approved') {
                throw new CashNetworkException('Agent non validé.', 403);
            }
            if ($agentUser->wallet?->country && $agentUser->wallet->country !== $v->country) {
                throw new CashNetworkException('Ce bon doit être retiré dans un autre pays.');
            }
            if ((int) $v->failed_attempts >= 5) {
                throw new CashNetworkException('Trop d\'essais erronés sur ce retrait : il est bloqué. Le client peut l\'annuler et en créer un nouveau.', 423);
            }

            if ($v->isThirdParty()) {
                if (trim((string) $name) === '' || trim((string) $phone) === '') {
                    throw new CashNetworkException('Retrait pour une autre personne : saisissez le nom et le numéro de téléphone du bénéficiaire.');
                }
                $okName = app(\App\Services\Compliance\IdDocumentCheck::class)->sameName((string) $name, (string) $v->beneficiary_name, '', true);
                $expected = \App\Support\Phone::candidates((string) $v->beneficiary_phone);
                $okPhone = $v->beneficiary_phone === null || array_intersect(\App\Support\Phone::candidates((string) $phone), $expected) !== [];
                if (! $okName || ! $okPhone) {
                    $v->increment('failed_attempts');
                    throw new CashNetworkException(match (true) {
                        ! $okName && ! $okPhone => 'Le nom et le téléphone du bénéficiaire ne correspondent pas à ce retrait.',
                        ! $okName => 'Le nom du bénéficiaire ne correspond pas à ce retrait (vérifiez sa pièce d\'identité).',
                        default => 'Le téléphone du bénéficiaire ne correspond pas à ce retrait.',
                    });
                }
            }

            // Déjà confirmé pour cet agent et encore valable : rien à redemander
            if ($v->confirmation_status === 'approved' && (int) $v->confirmation_agent_user_id === $agentUser->id
                && $v->confirmed_at?->gt(now()->subMinutes(self::CONFIRM_TTL_MINUTES))) {
                return $v;
            }
            $v->update(['confirmation_status' => 'awaiting_sender', 'confirmation_agent_user_id' => $agentUser->id,
                'confirmation_requested_at' => now(), 'confirmed_at' => null]);

            return $v->fresh();
        });

        if ($v->confirmation_status === 'awaiting_sender') {
            $who = $v->isThirdParty() ? $v->beneficiary_name : 'vous';
            $amount = number_format($v->amount, 0, ',', ' ') . ' ' . $v->currency;
            $agentLabel = $agentUser->full_name . ($agentUser->agent?->agent_code ? ' (' . $agentUser->agent->agent_code . ')' : '');
            app(\App\Services\Notifications\NotificationService::class)->toUser($v->owner, 'cash_out_confirm', 'Confirmez votre retrait',
                "L'agent {$agentLabel} s'apprête à remettre {$amount} à {$who}. Ouvrez OfficePay pour confirmer ou refuser. Si vous n'êtes pas à l'origine de ce retrait, refusez.",
                ['severity' => 'warning', 'data' => ['voucher_id' => $v->id, 'action' => 'cash_out_confirm'],
                    'sms' => "OfficePay: retrait de {$amount} en cours chez l'agent {$agentLabel}. Confirmez ou refusez dans l'application."]);
        }

        return $v;
    }

    /** Réponse de l'expéditeur (après PIN) : autorise ou refuse le paiement par l'agent. */
    public function senderConfirm(WithdrawalVoucher $voucher, User $user, bool $approve): WithdrawalVoucher
    {
        return DB::transaction(function () use ($voucher, $user, $approve) {
            $v = WithdrawalVoucher::whereKey($voucher->id)->lockForUpdate()->first();
            if ((int) $v->user_id !== $user->id) {
                throw new CashNetworkException('Ce retrait ne vous appartient pas.', 403);
            }
            if ($v->confirmation_status !== 'awaiting_sender' || ! $v->isRedeemable()
                || ! $v->confirmation_requested_at?->gt(now()->subMinutes(self::CONFIRM_TTL_MINUTES))) {
                throw new CashNetworkException('Aucune demande de retrait en attente de votre confirmation (déjà traitée ou expirée).');
            }
            $v->update(['confirmation_status' => $approve ? 'approved' : 'rejected', 'confirmed_at' => now()]);
            \App\Support\Audit::log($approve ? 'cash_out.sender_approved' : 'cash_out.sender_rejected', $v, ['agent_user_id' => $v->confirmation_agent_user_id], $user->id);

            return $v->fresh();
        });
    }

    /** Le paiement par l'agent exige l'accord de l'expéditeur, donné pour CET agent et encore valable. */
    protected function assertSenderApproved(WithdrawalVoucher $v, User $agentUser): void
    {
        if (! self::senderConfirmationRequired()) {
            return;
        }
        $fresh = $v->confirmed_at?->gt(now()->subMinutes(self::CONFIRM_TTL_MINUTES));
        if ($v->confirmation_status === 'approved' && (int) $v->confirmation_agent_user_id === $agentUser->id && $fresh) {
            return;
        }
        throw new CashNetworkException(match (true) {
            $v->confirmation_status === 'rejected' => 'L\'expéditeur a refusé ce retrait : ne remettez pas les espèces.',
            $v->confirmation_status === 'awaiting_sender' && (int) $v->confirmation_agent_user_id === $agentUser->id => 'En attente de la confirmation de l\'expéditeur dans son application.',
            default => 'Demandez d\'abord la confirmation de l\'expéditeur.',
        }, 409);
    }

    // =================================================== Helpers

    protected function uniqueVoucherCode(string $channel): string
    {
        // Cash pickup : 10 chiffres (lisible au comptoir) ; GAB : 12 chiffres
        $len = $channel === 'atm' ? 12 : 10;
        do {
            $code = (string) random_int(1, 9) . $this->digits($len - 1);
        } while (WithdrawalVoucher::where('code_hash', WithdrawalVoucher::hash($code))->exists());
        return $code;
    }

    protected function digits(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= random_int(0, 9);
        }
        return $s;
    }
}
