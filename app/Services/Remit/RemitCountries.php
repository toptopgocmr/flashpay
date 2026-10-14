<?php

namespace App\Services\Remit;

/**
 * Pays de destination des transferts internationaux (catalogue de base).
 * REMIT_COUNTRIES restreint la liste ; REMIT_PARTNERS choisit le partenaire par pays.
 */
class RemitCountries
{
    /** ISO2 => [nom, devise de paiement, indicatif, drapeau] */
    public const ALL = [
        // Afrique centrale (CEMAC) et RDC
        'CM' => ['Cameroun', 'XAF', '237', '🇨🇲'], 'GA' => ['Gabon', 'XAF', '241', '🇬🇦'], 'TD' => ['Tchad', 'XAF', '235', '🇹🇩'],
        'CF' => ['Centrafrique', 'XAF', '236', '🇨🇫'], 'GQ' => ['Guinée équatoriale', 'XAF', '240', '🇬🇶'], 'CD' => ['RD Congo', 'USD', '243', '🇨🇩'],
        'AO' => ['Angola', 'AOA', '244', '🇦🇴'],
        // Afrique de l'Ouest
        'SN' => ['Sénégal', 'XOF', '221', '🇸🇳'], 'CI' => ['Côte d\'Ivoire', 'XOF', '225', '🇨🇮'], 'ML' => ['Mali', 'XOF', '223', '🇲🇱'],
        'BF' => ['Burkina Faso', 'XOF', '226', '🇧🇫'], 'BJ' => ['Bénin', 'XOF', '229', '🇧🇯'], 'TG' => ['Togo', 'XOF', '228', '🇹🇬'],
        'NE' => ['Niger', 'XOF', '227', '🇳🇪'], 'GW' => ['Guinée-Bissau', 'XOF', '245', '🇬🇼'], 'GN' => ['Guinée', 'GNF', '224', '🇬🇳'],
        'GH' => ['Ghana', 'GHS', '233', '🇬🇭'], 'NG' => ['Nigeria', 'NGN', '234', '🇳🇬'], 'LR' => ['Liberia', 'LRD', '231', '🇱🇷'],
        'SL' => ['Sierra Leone', 'SLE', '232', '🇸🇱'], 'GM' => ['Gambie', 'GMD', '220', '🇬🇲'],
        // Afrique de l'Est / australe / du Nord
        'KE' => ['Kenya', 'KES', '254', '🇰🇪'], 'UG' => ['Ouganda', 'UGX', '256', '🇺🇬'], 'TZ' => ['Tanzanie', 'TZS', '255', '🇹🇿'],
        'RW' => ['Rwanda', 'RWF', '250', '🇷🇼'], 'BI' => ['Burundi', 'BIF', '257', '🇧🇮'], 'ET' => ['Éthiopie', 'ETB', '251', '🇪🇹'],
        'ZM' => ['Zambie', 'ZMW', '260', '🇿🇲'], 'MZ' => ['Mozambique', 'MZN', '258', '🇲🇿'], 'MG' => ['Madagascar', 'MGA', '261', '🇲🇬'],
        'ZA' => ['Afrique du Sud', 'ZAR', '27', '🇿🇦'], 'MA' => ['Maroc', 'MAD', '212', '🇲🇦'], 'TN' => ['Tunisie', 'TND', '216', '🇹🇳'],
        'EG' => ['Égypte', 'EGP', '20', '🇪🇬'],
        // Europe
        'FR' => ['France', 'EUR', '33', '🇫🇷'], 'BE' => ['Belgique', 'EUR', '32', '🇧🇪'], 'DE' => ['Allemagne', 'EUR', '49', '🇩🇪'],
        'ES' => ['Espagne', 'EUR', '34', '🇪🇸'], 'IT' => ['Italie', 'EUR', '39', '🇮🇹'], 'PT' => ['Portugal', 'EUR', '351', '🇵🇹'],
        'NL' => ['Pays-Bas', 'EUR', '31', '🇳🇱'], 'LU' => ['Luxembourg', 'EUR', '352', '🇱🇺'], 'CH' => ['Suisse', 'CHF', '41', '🇨🇭'],
        'GB' => ['Royaume-Uni', 'GBP', '44', '🇬🇧'],
        // Amériques, Moyen-Orient, Asie
        'US' => ['États-Unis', 'USD', '1', '🇺🇸'], 'CA' => ['Canada', 'CAD', '1', '🇨🇦'], 'BR' => ['Brésil', 'BRL', '55', '🇧🇷'],
        'AE' => ['Émirats arabes unis', 'AED', '971', '🇦🇪'], 'LB' => ['Liban', 'LBP', '961', '🇱🇧'], 'TR' => ['Turquie', 'TRY', '90', '🇹🇷'],
        'CN' => ['Chine', 'CNY', '86', '🇨🇳'], 'IN' => ['Inde', 'INR', '91', '🇮🇳'],
    ];

    public static function all(): array
    {
        $only = (array) config('flashpay.remit.countries', []);
        $home = strtoupper((string) config('flashpay.remit.from_country', 'CG'));
        $out = [];
        foreach (self::ALL as $iso => [$name, $cur, $dial, $flag]) {
            if ($iso === $home || ($only && ! in_array($iso, $only, true))) {
                continue;
            }
            $out[$iso] = ['iso' => $iso, 'name' => $name, 'currency' => $cur, 'dial' => $dial, 'flag' => $flag];
        }
        return $out;
    }

    public static function get(string $iso): ?array
    {
        return self::all()[strtoupper($iso)] ?? null;
    }

    /** Partenaires de remittance : nom et moyens de réception. */
    public const PARTNERS = [
        'wacepay_remit' => ['name' => 'WacePay', 'methods' => ['wallet', 'bank', 'cash']],
        'terrapay' => ['name' => 'TerraPay', 'methods' => ['wallet', 'bank']],
        'pawapay' => ['name' => 'PawaPay', 'methods' => ['wallet']],
    ];

    /**
     * Partenaire du pays : choix de la console (Pays & change › International), sinon
     * REMIT_PARTNERS (clé « * » = défaut). « off » = transferts fermés vers ce pays.
     */
    public static function partnerFor(string $iso): ?string
    {
        $iso = strtoupper($iso);
        $console = self::overrides();
        if (array_key_exists($iso, $console)) {
            $p = $console[$iso];
        } else {
            $map = (array) config('flashpay.remit.partners', []);
            $p = $map[$iso] ?? $map['*'] ?? null;
        }
        return isset(self::PARTNERS[$p]) ? $p : null;
    }

    /** Partenaire par défaut (REMIT_PARTNERS) sans le choix de la console. */
    public static function defaultPartnerFor(string $iso): ?string
    {
        $map = (array) config('flashpay.remit.partners', []);
        $p = $map[strtoupper($iso)] ?? $map['*'] ?? null;
        return isset(self::PARTNERS[$p]) ? $p : null;
    }

    /** Choix enregistrés dans la console : [ISO => partenaire | off] */
    public static function overrides(): array
    {
        try {
            return (array) app(\App\Services\Ops\PlatformSettings::class)->get('remit_partners', []);
        } catch (\Throwable) {
            return [];
        }
    }

    public static function partnerName(?string $p): string
    {
        return self::PARTNERS[$p]['name'] ?? (string) $p;
    }

    /** Moyens de réception proposés par partenaire. */
    public static function methodsOf(?string $partner): array
    {
        return self::PARTNERS[$partner]['methods'] ?? [];
    }
}
