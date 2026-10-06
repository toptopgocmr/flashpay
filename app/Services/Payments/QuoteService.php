<?php

namespace App\Services\Payments;

use App\Models\Merchant;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexException;
use App\Services\Peex\PeexGuard;

/**
 * Devis d'une opération interopérable : route (pays / opérateurs), zone,
 * frais FlashPay, change, montant reçu, disponibilité du corridor.
 *
 * Opérations :
 *   transfer          wallet|mobile  ->  wallet (utilisateur FlashPay) | mobile (tout opérateur couvert)
 *   merchant_payment  wallet|mobile  ->  wallet d'un marchand FlashPay (QR ou USSD)
 *   cash_in           mobile         ->  wallet (recharge)
 *   withdrawal        wallet         ->  mobile (retrait vers son numéro mobile money)
 *
 * Le montant saisi ($amount) est exprimé dans la devise de la SOURCE ;
 * les frais client s'ajoutent au montant, la commission marchand est
 * prélevée sur le montant reçu par le marchand.
 */
class QuoteService
{
    /** Codes machine des blocages du devis (l'appli propose une action adaptée). */
    protected array $codes = [];

    public function __construct(
        protected PeexCorridors $corridors,
        protected FeeService $fees,
        protected FxService $fx,
    ) {
    }

    /**
     * @param array{
     *   operation:string, amount:int,
     *   source:array{type:string, wallet?:Wallet, phone?:string, country?:string},
     *   destination:array{type:string, wallet?:Wallet, phone?:string, country?:string, merchant?:Merchant}
     * } $in
     */
    public function quote(array $in): array
    {
        $amount = (int) $in['amount'];
        if ($amount <= 0) {
            throw new PeexException('Montant invalide');
        }

        $problems = [];
        $this->codes = [];
        $src = $this->endpoint($in['source'], 'source', $problems);
        $dst = $this->endpoint($in['destination'], 'destination', $problems);

        $scope = $this->corridors->scope($src['country'], $dst['country']);

        $operation = $in['operation'];
        $feeOperation = match ($operation) {
            'transfer' => 'p2p',
            'merchant_payment' => 'merchant_payment',
            'cash_in' => 'cash_in',
            'withdrawal' => 'withdrawal',
            default => throw new PeexException("Opération inconnue : {$operation}"),
        };

        $fee = $this->fees->fee($feeOperation, $scope, $amount);
        // Paiement par carte Visa / Mastercard : frais de la passerelle carte en plus
        if ($src['type'] === 'card') {
            $fee += $this->fees->fee('card', 'national', $amount);
        }
        // Prélèvement bancaire : frais « bank_debit » de la grille (0 si non défini)
        if ($src['type'] === 'bank') {
            $fee += $this->fees->fee('bank_debit', 'national', $amount);
        }

        try {
            $fx = $this->fx->convert($amount, $src['currency'], $dst['currency']);
        } catch (\RuntimeException $e) {
            $problems[] = $e->getMessage();
            $fx = ['amount' => 0, 'rate' => 0, 'margin_percent' => 0, 'from' => $src['currency'], 'to' => $dst['currency'], 'source' => 'indisponible'];
        }

        $merchantFee = $operation === 'merchant_payment'
            ? $this->fees->fee('merchant_fee', $scope, $fx['amount'])
            : 0;

        if ($src['type'] === 'wallet' && isset($src['balance']) && $src['balance'] < $amount + $fee) {
            $this->codes[] = 'insufficient_balance';
            $problems[] = 'Solde insuffisant (' . number_format($src['balance'], 0, ',', ' ') . ' ' . $src['currency'] . ').';
        }

        // Mobile money -> mobile money autorisé sur TOUS les opérateurs, y compris
        // le même opérateur (décision du 29/09/2026) : collecte PEEX puis versement PEEX.

        // Contrôles PEEX avant tout débit (comptes, services, soldes) — seulement
        // si l'opération est par ailleurs possible, pour limiter les appels.
        if (empty($problems)) {
            $this->peexChecks($src, $dst, (int) $fx['amount'], $problems);
        }

        return [
            'operation' => $operation,
            'scope' => $scope,
            'source' => $src,
            'destination' => $dst,
            'amount' => $amount,
            'currency' => $src['currency'],
            'fee' => $fee,
            'total' => $amount + $fee,
            'fx' => $fx,
            'receive_amount' => max(0, $fx['amount'] - $merchantFee),
            'receive_currency' => $dst['currency'],
            'merchant_fee' => $merchantFee,
            'gross_destination_amount' => $fx['amount'],
            'available' => empty($problems),
            'problems' => array_values(array_unique($problems)),
            'problem_codes' => array_values(array_unique($this->codes)),
            'async' => in_array($src['type'], ['mobile', 'card', 'bank'], true) || $dst['type'] === 'mobile',
        ];
    }

    /**
     * 1. payeur mobile : collecte PEEX activée + compte actif (nom du titulaire) ;
     * 2. bénéficiaire mobile : compte actif (nom du titulaire) + service de
     *    versement activé + solde PEEX suffisant ;
     * 3. si payeur ET bénéficiaire mobiles : remboursement automatique possible
     *    vers le pays du payeur (sinon on refuse d'encaisser).
     */
    protected function peexChecks(array &$src, array &$dst, int $outAmount, array &$problems): void
    {
        $guard = app(PeexGuard::class);

        // PEEX refuse les petits montants (« Fees is not yet defined… ») : aucune grille
        // de frais n'existe chez lui sous ce seuil. On l'annonce avant tout débit.
        $min = (int) config('flashpay.peex.min_amount', 100);
        if (($src['type'] === 'mobile' || $dst['type'] === 'mobile') && $outAmount < $min) {
            $this->codes[] = 'min_amount';
            $problems[] = 'Montant minimum pour le mobile money : ' . number_format($min, 0, ',', ' ') . ' XAF.';
            return;
        }

        // Passerelle choisie pour le pays (console › Pays & change) : TOUS les contrôles
        // du flux passent par elle — aucun appel PEEX quand le pays est confié à WacePay.
        $collectBy = $src['type'] === 'mobile' ? $this->partnerFor($src['country'], 'collect_partner') : null;
        $payoutBy = $dst['type'] === 'mobile' ? $this->partnerFor($dst['country'], 'payout_partner') : null;

        if ($src['type'] === 'mobile' && $collectBy === 'peex') {
            if ($p = $guard->checkCollect()) {
                $this->codes[] = 'collect_unavailable';
                $problems[] = $p;
                return;
            }
            $v = $guard->verifyAccount($src, 'source');
            if ($v['problem']) {
                $problems[] = $v['problem'];
            }
            $src['verified_name'] = $v['name'];
        }
        if ($src['type'] === 'mobile' && $collectBy === 'digitwace' && ! app(\App\Services\Digitwace\DigitwaceClient::class)->enabled()) {
            $problems[] = "Collecte depuis {$src['country_name']} confiée à WacePay, mais WacePay n'est pas configuré.";
        }

        if ($dst['type'] === 'mobile' && $payoutBy === 'peex') {
            $v = $guard->verifyAccount($dst, 'destination');
            if ($v['problem']) {
                $problems[] = $v['problem'];
            }
            $dst['verified_name'] = $v['name'];
            if ($v['name'] && empty($dst['name'])) {
                $dst['name'] = $v['name'];
            }
            if ($p = $guard->checkPayout($dst['country'], $outAmount)) {
                $this->codes[] = 'payout_unavailable';
                $problems[] = $p;
            }
        }
        if ($dst['type'] === 'mobile' && $payoutBy === 'digitwace') {
            if (! app(\App\Services\Digitwace\DigitwaceClient::class)->enabled()) {
                $problems[] = "Versements vers {$dst['country_name']} confiés à WacePay, mais WacePay n'est pas configuré.";
            } elseif (config('flashpay.digitwace.check_balance', true)) {
                $avail = app(\App\Services\Digitwace\WacepayBalanceService::class)->availableFor($dst['currency']);
                if ($avail !== null && $avail < $outAmount) {
                    $this->codes[] = 'payout_unavailable';
                    $problems[] = "Les envois vers {$dst['country_name']} sont momentanément suspendus (réapprovisionnement du service de versement en cours). Réessayez plus tard ou envoyez un montant plus petit.";
                }
            }
        }
        // Remboursement automatique d'une collecte PEEX = versement PEEX vers le payeur.
        // (Collecte WacePay : remboursement sur le wallet FlashPay du client.)
        if ($src['type'] === 'mobile' && $dst['type'] === 'mobile' && $collectBy === 'peex' && ! $src['payout']) {
            $problems[] = "Remboursement automatique impossible vers {$src['country_name']} en cas d'échec : opération non disponible.";
        }
    }

    /** Partenaire (peex | digitwace) qui gère ce flux pour le pays. */
    protected function partnerFor(?string $iso, string $field): string
    {
        if (! $iso) {
            return 'peex';
        }
        try {
            return ($this->corridors->country($iso)[$field] ?? 'peex') === 'digitwace' ? 'digitwace' : 'peex';
        } catch (\Throwable) {
            return 'peex';
        }
    }

    protected function endpoint(array $e, string $side, array &$problems): array
    {
        $type = $e['type'];

        if (in_array($type, ['wallet', 'merchant'], true)) {
            $wallet = $e['wallet'] ?? $e['merchant']?->user?->wallet ?? null;
            if (! $wallet) {
                throw new PeexException($type === 'merchant' ? 'Marchand introuvable' : 'Wallet introuvable');
            }
            $user = $wallet->user;
            $country = strtoupper($wallet->country ?: $this->countryOfUser($user));
            $c = $this->corridors->country($country);

            return [
                'type' => 'wallet',
                'wallet_id' => $wallet->id,
                'user_id' => $user?->id,
                'name' => $type === 'merchant' ? ($e['merchant']->business_name ?? $user?->full_name) : $user?->full_name,
                'merchant_id' => $type === 'merchant' ? $e['merchant']->id : null,
                'phone' => $user?->phone,
                'country' => $country,
                'country_name' => $c['name'],
                'flag' => $c['flag'] ?? '',
                'zone' => $c['zone'],
                'currency' => $wallet->currency ?: $c['currency'],
                'balance' => $side === 'source' ? (int) $wallet->balance : null,
                'operator' => 'FlashPay',
                'rail' => 'wallet',
            ];
        }

        // Compte bancaire (prélèvement, page sécurisée de la banque / WacePay)
        if ($type === 'bank' && $side === 'source') {
            $wallet = $e['wallet'] ?? throw new PeexException('Wallet introuvable');
            $country = strtoupper($wallet->country ?: $this->countryOfUser($wallet->user));
            $c = $this->corridors->country($country);
            if (! PaymentMethodsService::bankDebitEnabled($country)) {
                $problems[] = 'La recharge depuis un compte bancaire n\'est pas encore disponible.';
            }
            return [
                'type' => 'bank',
                'name' => $wallet->user?->full_name,
                'phone' => null,
                'country' => $country,
                'country_name' => $c['name'],
                'flag' => $c['flag'] ?? '',
                'zone' => $c['zone'],
                'currency' => $wallet->currency ?: $c['currency'],
                'operator' => 'Compte bancaire',
                'rail' => 'bank',
            ];
        }

        // Carte Visa / Mastercard (prépayée ou bancaire) : pays et devise du wallet du client
        if ($type === 'card') {
            $wallet = $e['wallet'] ?? throw new PeexException('Wallet introuvable');
            $country = strtoupper($wallet->country ?: $this->countryOfUser($wallet->user));
            $c = $this->corridors->country($country);
            if (! PaymentMethodsService::cardEnabled($country)) {
                $problems[] = 'Le paiement par carte n\'est pas encore disponible.';
            }
            return [
                'type' => 'card',
                'name' => $wallet->user?->full_name,
                'phone' => null,
                'country' => $country,
                'country_name' => $c['name'],
                'flag' => $c['flag'] ?? '',
                'zone' => $c['zone'],
                'currency' => $wallet->currency ?: $c['currency'],
                'operator' => 'Carte Visa / Mastercard',
                'rail' => 'card',
            ];
        }

        // Numéro mobile money
        $r = $this->corridors->resolve($e['phone'] ?? '', $e['country'] ?? null, true);
        if ($side === 'source' && ! $r['collect']) {
            $problems[] = "La collecte depuis {$r['country_name']} n'est pas encore activée.";
        }
        if ($side === 'destination' && ! $r['payout']) {
            $problems[] = "Les versements vers {$r['country_name']} ne sont pas encore activés.";
        }
        if (! $r['corridor']) {
            $problems[] = "Opérateur non reconnu pour {$r['phone']}.";
        }

        return ['type' => 'mobile', 'name' => $e['name'] ?? null] + $r;
    }

    public function countryOfUser(?User $user): string
    {
        if ($user?->phone) {
            try {
                return $this->corridors->resolve($user->phone)['country'];
            } catch (\Throwable) {
            }
        }
        return config('flashpay.peex.default_country', 'CG');
    }
}
