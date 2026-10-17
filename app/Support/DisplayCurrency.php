<?php

namespace App\Support;

use App\Services\Payments\FxService;
use App\Services\Peex\PeexCorridors;

/**
 * Devise d'AFFICHAGE d'un wallet : le solde reste tenu dans la devise du wallet (XAF),
 * mais un client dont le numéro est d'un pays à une autre monnaie (France → EUR,
 * États-Unis → USD…) voit tout converti : solde, montants, frais, totaux.
 * L'app convertit aussi sa saisie (en EUR, avec centimes) en XAF avant l'envoi.
 *
 * rate = unités de la devise d'affichage pour 1 unité de la devise du wallet (taux moyen,
 * sans marge) ; si aucun taux n'est configuré, l'affichage reste dans la devise du wallet.
 */
class DisplayCurrency
{
    /** Devises sans décimales (ISO 4217). */
    protected const ZERO_DECIMALS = ['XAF', 'XOF', 'XPF', 'JPY', 'KRW', 'GNF', 'RWF', 'UGX', 'BIF', 'DJF', 'KMF', 'VND', 'PYG', 'CLP', 'ISK', 'VUV'];

    protected const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CAD' => 'CA$', 'CHF' => 'CHF', 'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹', 'NGN' => '₦', 'GHS' => 'GH₵', 'ZAR' => 'R', 'MAD' => 'MAD', 'AED' => 'AED'];

    protected static array $memo = [];

    /** @return array{currency:string, rate:float, decimals:int, symbol:string, base:string, converted:bool} */
    public static function for(?string $country, string $walletCurrency = 'XAF'): array
    {
        $walletCurrency = strtoupper($walletCurrency ?: 'XAF');
        $key = strtoupper((string) $country) . '|' . $walletCurrency;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $cur = $walletCurrency;
        try {
            $cur = strtoupper((string) (app(PeexCorridors::class)->country((string) $country)['currency'] ?? $walletCurrency));
        } catch (\Throwable) {
            // pays inconnu : devise du wallet
        }
        $rate = 1.0;
        if ($cur !== $walletCurrency && config('flashpay.display_currency.enabled', true)) {
            try {
                $rate = (float) app(FxService::class)->rate($walletCurrency, $cur)[0];
            } catch (\Throwable) {
                $cur = $walletCurrency; // aucun taux configuré : on n'affiche pas une conversion fausse
            }
        } else {
            $cur = $walletCurrency;
        }
        return self::$memo[$key] = [
            'currency' => $cur,
            'rate' => $rate > 0 ? round($rate, 10) : 1.0,
            'decimals' => in_array($cur, self::ZERO_DECIMALS, true) ? 0 : 2,
            'symbol' => self::SYMBOLS[$cur] ?? $cur,
            'base' => $walletCurrency,
            'converted' => $cur !== $walletCurrency,
        ];
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
