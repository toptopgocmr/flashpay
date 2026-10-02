<?php

namespace App\Services\Peex;

use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\QuoteService;
use App\Services\SwitchService;

/**
 * Parcours de paiement interopérables (style Wave), routés via PEEX :
 *
 *   transfer()     wallet | mobile  ->  utilisateur FlashPay | n'importe quel numéro couvert
 *   deposit()      mobile           ->  wallet (recharge, gratuit)
 *   withdraw()     wallet           ->  mobile money (retrait, gratuit)
 *   payMerchant()  wallet | mobile  ->  marchand FlashPay (QR dans l'app ou USSD initié par le marchand)
 *
 * Chaque parcours calcule d'abord un devis (QuoteService) puis exécute la
 * transaction via le Switch. Utilisé par l'API mobile, l'admin et les commandes.
 */
class PeexFlowService
{
    public function __construct(
        protected SwitchService $switch,
        protected QuoteService $quotes,
        protected PeexCorridors $corridors,
    ) {
    }

    /** Rail FlashPay : « wallet » (interne) ou « peex » (passerelle externe par défaut). */
    public function railFor(array $route): string
    {
        return ($route['rail'] ?? null) === 'wallet' ? 'wallet' : 'peex';
    }

    /** Rail de COLLECTE : partenaire choisi pour le pays (PEEX ou WacePay). */
    public function collectRailFor(array $route): string
    {
        $rail = $this->railFor($route);
        if ($rail !== 'peex' || empty($route['country'])) {
            return $rail;
        }
        try {
            $partner = $this->corridors->country((string) $route['country'])['collect_partner'] ?? 'peex';
        } catch (\Throwable) {
            return $rail;
        }
        if ($partner !== 'digitwace') {
            return 'peex';
        }
        if (! config('flashpay.rails.digitwace.enabled', false) || ! app(\App\Services\Digitwace\DigitwaceClient::class)->enabled()) {
            throw new PeexException('Collecte depuis ce pays confiée à WacePay, mais WacePay n\'est pas encore configuré. Contactez le support FlashPay.');
        }
        return 'digitwace';
    }

    /** Rail de VERSEMENT : WacePay (Digitwace) pour les pays configurés, sinon PEEX. */
    public function payoutRailFor(array $route): string
    {
        $rail = $this->railFor($route);
        if ($rail !== 'peex' || empty($route['country'])) {
            return $rail;
        }
        // Partenaire choisi pour ce pays dans la console (Pays & change › Partenaire)
        try {
            $partner = $this->corridors->country((string) $route['country'])['payout_partner'] ?? 'peex';
        } catch (\Throwable) {
            return $rail;
        }
        if ($partner !== 'digitwace') {
            return 'peex';
        }
        if (! config('flashpay.rails.digitwace.enabled', false) || ! app(\App\Services\Digitwace\DigitwaceClient::class)->enabled()) {
            // WacePay choisi mais pas configuré : on refuse plutôt que de router en silence ailleurs
            throw new PeexException('Versements vers ce pays confiés à WacePay, mais WacePay n\'est pas encore configuré. Contactez le support FlashPay.');
        }

        return 'digitwace';
    }

    // ------------------------------------------------------------ Devis

    public function quoteTransfer(User $user, string $source, ?string $sourcePhone, string $destPhone, int $amount, array $opt = []): array
    {
        return $this->quotes->quote([
            'operation' => 'transfer',
            'amount' => $amount,
            'source' => match ($source) {
                'wallet' => ['type' => 'wallet', 'wallet' => $this->walletOf($user)],
                'card' => ['type' => 'card', 'wallet' => $this->walletOf($user)],
                default => ['type' => 'mobile', 'phone' => $sourcePhone ?: $user->phone, 'country' => $opt['source_country'] ?? null],
            },
            'destination' => $this->destinationFor($destPhone, $opt),
        ]);
    }

    public function quoteDeposit(User $user, string $phone, int $amount, array $opt = []): array
    {
        return $this->quotes->quote([
            'operation' => 'cash_in',
            'amount' => $amount,
            'source' => ['type' => 'mobile', 'phone' => $phone, 'country' => $opt['source_country'] ?? null],
            'destination' => ['type' => 'wallet', 'wallet' => $this->walletOf($user)],
        ]);
    }

    /** Recharge du wallet par carte Visa / Mastercard (prépayée ou bancaire). */
    public function quoteCardDeposit(User $user, int $amount): array
    {
        $wallet = $this->walletOf($user);
        return $this->quotes->quote([
            'operation' => 'cash_in',
            'amount' => $amount,
            'source' => ['type' => 'card', 'wallet' => $wallet],
            'destination' => ['type' => 'wallet', 'wallet' => $wallet],
        ]);
    }

    public function cardDeposit(User $user, int $amount, array $meta = []): Transaction
    {
        return $this->execute($user, $this->quoteCardDeposit($user, $amount), 'cash_in', $meta);
    }

    public function quoteWithdraw(User $user, string $phone, int $amount, array $opt = []): array
    {
        return $this->quotes->quote([
            'operation' => 'withdrawal',
            'amount' => $amount,
            'source' => ['type' => 'wallet', 'wallet' => $this->walletOf($user)],
            'destination' => ['type' => 'mobile', 'phone' => $phone, 'country' => $opt['destination_country'] ?? null, 'name' => $user->full_name],
        ]);
    }

    public function quoteMerchant(?User $payer, Merchant $merchant, string $source, ?string $payerPhone, int $amount, array $opt = []): array
    {
        return $this->quotes->quote([
            'operation' => 'merchant_payment',
            'amount' => $amount,
            'source' => match ($source) {
                'wallet' => ['type' => 'wallet', 'wallet' => $this->walletOf($payer)],
                // Carte Visa / Mastercard liée au profil : page de paiement 3-D Secure
                'card' => ['type' => 'card', 'wallet' => $this->walletOf($payer)],
                default => ['type' => 'mobile', 'phone' => $payerPhone ?: $payer?->phone, 'country' => $opt['source_country'] ?? null],
            },
            'destination' => ['type' => 'merchant', 'merchant' => $merchant],
        ]);
    }

    // --------------------------------------------------------- Exécution

    public function transfer(User $user, string $source, ?string $sourcePhone, string $destPhone, int $amount, array $opt = []): Transaction
    {
        $q = $this->quoteTransfer($user, $source, $sourcePhone, $destPhone, $amount, $opt);

        return $this->execute($user, $q, 'p2p', [
            'beneficiary_name' => $opt['beneficiary_name'] ?? $q['destination']['name'] ?? null,
            'purpose' => $opt['purpose'] ?? null,
            'note' => $opt['note'] ?? null,
        ]);
    }

    public function deposit(User $user, string $phone, int $amount, array $opt = []): Transaction
    {
        return $this->execute($user, $this->quoteDeposit($user, $phone, $amount, $opt), 'cash_in', $opt['meta'] ?? []);
    }

    public function withdraw(User $user, string $phone, int $amount, array $opt = []): Transaction
    {
        return $this->execute($user, $this->quoteWithdraw($user, $phone, $amount, $opt), 'withdrawal', [
            'beneficiary_name' => $user->full_name,
        ]);
    }

    /**
     * @param string $method qr | pay_code | ussd | manual | nfc
     */
    public function payMerchant(User $initiator, ?User $payer, Merchant $merchant, string $source, ?string $payerPhone, int $amount, string $method = 'qr', array $opt = []): Transaction
    {
        if ($merchant->validation_status === 'rejected') {
            throw new PeexException('Ce marchand n\'est pas autorisé à encaisser.');
        }

        $q = $this->quoteMerchant($payer, $merchant, $source, $payerPhone, $amount, $opt);
        $type = match ($method) {
            'qr', 'pay_code', 'dynamic_qr' => 'qr_payment',
            'nfc' => 'nfc_payment',
            'ussd' => 'collection',
            'manual', 'payment_link' => 'manual_payment',
            'ecommerce' => 'ecommerce_payment',
            'mini_program' => 'mini_program_payment',
            default => 'merchant_payment',
        };

        return $this->execute($initiator, $q, $type, [
            'merchant_id' => $merchant->id,
            'merchant_name' => $merchant->business_name,
            'method' => $method,
            'payer_name' => $payer?->full_name ?? ($opt['payer_name'] ?? 'Client ' . $merchant->business_name),
            'description' => 'Paiement ' . $merchant->business_name,
            'outlet_id' => $opt['outlet_id'] ?? null,
        ] + ($opt['meta'] ?? []));
    }

    /** Exécute un devis via le Switch. */
    public function execute(User $initiator, array $q, string $type, array $meta = []): Transaction
    {
        if (! $q['available']) {
            throw new PeexException(implode(' ', $q['problems']));
        }

        $src = $q['source'];
        $dst = $q['destination'];
        $converted = $q['currency'] !== $q['receive_currency'];

        // Revérification à l'instant du débit, sans cache : service de collecte
        // actif et solde PEEX de versement suffisant (montants engagés déduits).
        $guard = app(PeexGuard::class);
        $collectRail = $src['type'] === 'mobile' ? $this->collectRailFor($src) : null;
        if ($src['type'] === 'mobile' && $collectRail === 'peex' && ($p = $guard->checkCollect(fresh: true))) {
            throw new PeexException($p);
        }
        $payoutRail = $dst['type'] === 'wallet' ? 'wallet' : $this->payoutRailFor($dst);
        if ($dst['type'] === 'mobile' && $payoutRail === 'digitwace' && config('flashpay.digitwace.check_balance', true)) {
            $need = (int) $q['gross_destination_amount'];
            $avail = app(\App\Services\Digitwace\WacepayBalanceService::class)->availableFor($q['receive_currency']);
            if ($avail !== null && $avail < $need) {
                throw new PeexException('Service de versement momentanément indisponible vers ce pays (solde partenaire insuffisant). Réessayez plus tard.');
            }
        }
        if ($dst['type'] === 'mobile' && $payoutRail === 'peex' && ($p = $guard->checkPayout($dst['country'], (int) $q['gross_destination_amount'], fresh: true))) {
            throw new PeexException($p);
        }

        $payload = [
            'type' => $type,
            'scope' => $q['scope'],
            'source_rail' => match ($src['type']) { 'wallet' => 'wallet', 'card' => 'card', default => $collectRail },
            'source_account' => $src['type'] === 'mobile' ? $src['phone'] : null,
            'source_wallet_id' => $src['wallet_id'] ?? null,
            'destination_rail' => $payoutRail,
            'destination_account' => $dst['type'] === 'mobile' ? $dst['phone'] : null,
            'destination_wallet_id' => $dst['wallet_id'] ?? null,
            'amount' => $q['amount'],
            'fee' => $q['fee'],
            'merchant_fee' => $q['merchant_fee'],
            'currency' => $q['currency'],
            'destination_amount' => $converted ? $q['gross_destination_amount'] : null,
            'destination_currency' => $converted ? $q['receive_currency'] : null,
            'initiated_by' => $initiator->id,
            'meta' => array_filter($meta + [
                'channel' => 'peex',
                'source_country' => $src['country'],
                'source_corridor' => $src['corridor'] ?? null,
                'source_operator' => $src['operator'] ?? null,
                'destination_country' => $dst['country'],
                'destination_corridor' => $dst['corridor'] ?? null,
                'destination_operator' => $dst['operator'] ?? null,
                'sender_name' => $meta['payer_name'] ?? $src['name'] ?? $initiator->full_name,
                'sender_phone' => $src['phone'] ?? $initiator->phone,
                'payer_name' => $meta['payer_name'] ?? $src['name'] ?? $initiator->full_name,
                // Titulaires vérifiés chez l'opérateur (Verify Wallet) : utilisés pour PEEX
                'payer_verified_name' => $src['verified_name'] ?? null,
                'beneficiary_verified_name' => $dst['verified_name'] ?? null,
                'fx' => $converted ? $q['fx'] : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        // Carte : la source est confirmée par la page de paiement sécurisée (3-D Secure)
        if ($src['type'] === 'card') {
            $payload['source_rail'] = 'card';
            $tx = $this->switch->createPending($payload, 'awaiting_card');
            return app(\App\Services\Payments\CardPaymentService::class)->startCheckout($tx);
        }

        return $this->switch->process($payload);
    }

    // ------------------------------------------------ Compatibilité (admin, CLI)

    public function cashIn(User $user, string $phone, int $amount, array $meta = []): Transaction
    {
        return $this->deposit($user, $phone, $amount, $meta);
    }

    public function payout(User $user, string $phone, int $amount, array $meta = [], string $type = 'withdrawal'): Transaction
    {
        $q = $this->quoteWithdraw($user, $phone, $amount, $meta);
        return $this->execute($user, $q, $type, array_filter(['beneficiary_name' => $meta['beneficiary_name'] ?? null, 'test' => $meta['test'] ?? null]));
    }

    public function mobileTransfer(User $user, string $fromPhone, string $toPhone, int $amount, array $meta = []): Transaction
    {
        return $this->transfer($user, 'mobile', $fromPhone, $toPhone, $amount, $meta + ['deliver_to' => 'mobile']);
    }

    // ------------------------------------------------------------ Helpers

    /** Destinataire : wallet si le numéro appartient à un utilisateur FlashPay (sauf deliver_to=mobile). */
    protected function destinationFor(string $phone, array $opt): array
    {
        $route = $this->corridors->resolve($phone, $opt['destination_country'] ?? null);

        if (($opt['deliver_to'] ?? 'auto') !== 'mobile') {
            $user = $this->findUserByPhone($route['phone']);
            if ($user && ($wallet = $user->wallet) && $user->hasRole('client')) {
                return ['type' => 'wallet', 'wallet' => $wallet];
            }
        }

        return ['type' => 'mobile', 'phone' => $route['phone'], 'country' => $route['country'], 'name' => $opt['beneficiary_name'] ?? null];
    }

    public function findUserByPhone(string $international): ?User
    {
        $digits = ltrim($international, '+');
        return User::whereIn('phone', array_unique([$digits, '+' . $digits, $international, ...\App\Support\Phone::candidates($international)]))->first();
    }

    public function walletOf(?User $user): Wallet
    {
        if (! $user) {
            throw new PeexException('Utilisateur requis pour un paiement depuis le wallet');
        }
        if ($user->wallet) {
            return $user->wallet;
        }
        $country = $this->quotes->countryOfUser($user);
        return Wallet::create([
            'user_id' => $user->id,
            'balance' => 0,
            'currency' => $this->corridors->country($country)['currency'],
            'country' => $country,
        ]);
    }
}
