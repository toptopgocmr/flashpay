<?php

namespace App\Services\Digitwace;

/**
 * Référentiel pays (hors config/corridors.php) utilisé pour créer automatiquement
 * un corridor quand WacePay annonce un pays : nom, indicatif, longueur du numéro
 * mobile national (sans le 0 de tête), devise, code ISO3.
 * Un pays absent d'ici est quand même enregistré dans la couverture WacePay,
 * mais son corridor doit être complété dans la console (indicatif / longueur).
 */
class CountryReference
{
    /** iso2 => [nom, iso3, indicatif, longueur nationale, devise] */
    public const COUNTRIES = [
        // Afrique de l'Ouest (hors UEMOA)
        'NG' => ['Nigeria', 'NGA', '234', 10, 'NGN'],
        'GH' => ['Ghana', 'GHA', '233', 9, 'GHS'],
        'LR' => ['Liberia', 'LBR', '231', 9, 'LRD'],
        'SL' => ['Sierra Leone', 'SLE', '232', 8, 'SLE'],
        'GM' => ['Gambie', 'GMB', '220', 7, 'GMD'],
        'MR' => ['Mauritanie', 'MRT', '222', 8, 'MRU'],
        'CV' => ['Cap-Vert', 'CPV', '238', 7, 'CVE'],
        // Afrique centrale / de l'Est / australe
        'ST' => ['Sao Tomé-et-Principe', 'STP', '239', 7, 'STN'],
        'AO' => ['Angola', 'AGO', '244', 9, 'AOA'],
        'KE' => ['Kenya', 'KEN', '254', 9, 'KES'],
        'UG' => ['Ouganda', 'UGA', '256', 9, 'UGX'],
        'TZ' => ['Tanzanie', 'TZA', '255', 9, 'TZS'],
        'RW' => ['Rwanda', 'RWA', '250', 9, 'RWF'],
        'BI' => ['Burundi', 'BDI', '257', 8, 'BIF'],
        'ET' => ['Éthiopie', 'ETH', '251', 9, 'ETB'],
        'SS' => ['Soudan du Sud', 'SSD', '211', 9, 'SSP'],
        'SD' => ['Soudan', 'SDN', '249', 9, 'SDG'],
        'DJ' => ['Djibouti', 'DJI', '253', 8, 'DJF'],
        'KM' => ['Comores', 'COM', '269', 7, 'KMF'],
        'MG' => ['Madagascar', 'MDG', '261', 9, 'MGA'],
        'MU' => ['Maurice', 'MUS', '230', 8, 'MUR'],
        'SC' => ['Seychelles', 'SYC', '248', 7, 'SCR'],
        'ZM' => ['Zambie', 'ZMB', '260', 9, 'ZMW'],
        'MW' => ['Malawi', 'MWI', '265', 9, 'MWK'],
        'MZ' => ['Mozambique', 'MOZ', '258', 9, 'MZN'],
        'ZW' => ['Zimbabwe', 'ZWE', '263', 9, 'USD'],
        'ZA' => ['Afrique du Sud', 'ZAF', '27', 9, 'ZAR'],
        'BW' => ['Botswana', 'BWA', '267', 8, 'BWP'],
        'NA' => ['Namibie', 'NAM', '264', 9, 'NAD'],
        'LS' => ['Lesotho', 'LSO', '266', 8, 'LSL'],
        'SZ' => ['Eswatini', 'SWZ', '268', 8, 'SZL'],
        // Afrique du Nord
        'MA' => ['Maroc', 'MAR', '212', 9, 'MAD'],
        'TN' => ['Tunisie', 'TUN', '216', 8, 'TND'],
        'DZ' => ['Algérie', 'DZA', '213', 9, 'DZD'],
        'EG' => ['Égypte', 'EGY', '20', 10, 'EGP'],
        // Europe
        'FR' => ['France', 'FRA', '33', 9, 'EUR'],
        'BE' => ['Belgique', 'BEL', '32', 9, 'EUR'],
        'ES' => ['Espagne', 'ESP', '34', 9, 'EUR'],
        'PT' => ['Portugal', 'PRT', '351', 9, 'EUR'],
        'IT' => ['Italie', 'ITA', '39', 10, 'EUR'],
        'NL' => ['Pays-Bas', 'NLD', '31', 9, 'EUR'],
        'CH' => ['Suisse', 'CHE', '41', 9, 'CHF'],
        'GB' => ['Royaume-Uni', 'GBR', '44', 10, 'GBP'],
        // Amériques
        'US' => ['États-Unis', 'USA', '1', 10, 'USD'],
        'CA' => ['Canada', 'CAN', '1', 10, 'CAD'],
        'HT' => ['Haïti', 'HTI', '509', 8, 'HTG'],
        'BR' => ['Brésil', 'BRA', '55', 11, 'BRL'],
        // Asie / Moyen-Orient
        'CN' => ['Chine', 'CHN', '86', 11, 'CNY'],
        'IN' => ['Inde', 'IND', '91', 10, 'INR'],
        'AE' => ['Émirats arabes unis', 'ARE', '971', 9, 'AED'],
        'TR' => ['Turquie', 'TUR', '90', 10, 'TRY'],
        'LB' => ['Liban', 'LBN', '961', 8, 'LBP'],
        'PH' => ['Philippines', 'PHL', '63', 10, 'PHP'],
        'BD' => ['Bangladesh', 'BGD', '880', 10, 'BDT'],
        'PK' => ['Pakistan', 'PAK', '92', 10, 'PKR'],
        'NP' => ['Népal', 'NPL', '977', 10, 'NPR'],
    ];

    /** ISO3 des pays déjà dans config/corridors.php (pour reconnaître les réponses WacePay en ISO3). */
    public const CONFIG_ISO3 = [
        'COG' => 'CG', 'CMR' => 'CM', 'GAB' => 'GA', 'TCD' => 'TD', 'CAF' => 'CF', 'GNQ' => 'GQ',
        'SEN' => 'SN', 'CIV' => 'CI', 'MLI' => 'ML', 'BFA' => 'BF', 'BEN' => 'BJ', 'TGO' => 'TG',
        'NER' => 'NE', 'GNB' => 'GW', 'COD' => 'CD', 'GIN' => 'GN',
    ];

    /** Code pays WacePay (ISO2, ISO3 ou nom) → ISO2. */
    public static function iso2(?string $code): ?string
    {
        $c = strtoupper(trim((string) $code));
        if ($c === '') {
            return null;
        }
        if (strlen($c) === 2) {
            return $c;
        }
        if (strlen($c) === 3) {
            if (isset(self::CONFIG_ISO3[$c])) {
                return self::CONFIG_ISO3[$c];
            }
            foreach (self::COUNTRIES as $iso => $row) {
                if ($row[1] === $c) {
                    return $iso;
                }
            }
        }
        foreach (self::COUNTRIES as $iso => $row) {
            if (mb_strtoupper($row[0]) === mb_strtoupper(trim((string) $code))) {
                return $iso;
            }
        }
        return null;
    }

    /** Drapeau emoji à partir du code ISO2. */
    public static function flag(string $iso): string
    {
        $iso = strtoupper($iso);
        return strlen($iso) === 2
            ? mb_chr(0x1F1E6 + ord($iso[0]) - 65) . mb_chr(0x1F1E6 + ord($iso[1]) - 65)
            : '';
    }
}
