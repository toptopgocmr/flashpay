<?php

/*
|--------------------------------------------------------------------------
| Corridors FlashPay (pays / opérateurs) — CEMAC, UEMOA, RDC, Guinée
|--------------------------------------------------------------------------
| - zone        : CEMAC | UEMOA | RDC | GUINEE (national / régional / international)
| - dial        : indicatif international
| - local_length: nombre de chiffres du numéro national envoyé après l'indicatif
|                 (Congo, Gabon, Côte d'Ivoire, Bénin gardent le 0 initial)
| - currency    : devise de réception
| - collect / payout : activation côté FlashPay (surchargeable par .env :
|   PEEX_<ISO>_COLLECT / PEEX_<ISO>_PAYOUT). L'activation effective dépend
|   aussi de votre compte PEEX (support@peexit.com).
| - payout_api  : disbursement | remittance
| - operators   : préfixes du numéro national -> corridor PEEX (rail toujours « peex »).
|   ⚠ Préfixes indicatifs : si aucun ne correspond, FlashPay interroge PEEX
|   (clients/verify_phoneNumber) pour connaître l'opérateur.
*/

$op = fn (string $label, array $prefixes) => ['label' => $label, 'rail' => 'peex', 'prefixes' => $prefixes];
$on = fn (string $iso, string $what, bool $default) => filter_var(env("PEEX_{$iso}_{$what}", $default), FILTER_VALIDATE_BOOLEAN);
$c = fn (string $iso, string $name, string $zone, string $dial, int $len, string $cur, string $flag, bool $collect, bool $payout, string $api, array $ops) => [
    'name' => $name, 'zone' => $zone, 'dial' => $dial, 'local_length' => $len, 'currency' => $cur, 'flag' => $flag,
    'collect' => $on($iso, 'COLLECT', $collect), 'payout' => $on($iso, 'PAYOUT', $payout),
    'payout_api' => env("PEEX_{$iso}_PAYOUT_API", $api), 'operators' => $ops,
];

return [
    // ================================================================ CEMAC (XAF)
    'CG' => $c('CG', 'Congo-Brazzaville', 'CEMAC', '242', 9, 'XAF', '🇨🇬', true, true, 'disbursement', [
        'mtn-cg' => $op('MTN Mobile Money', ['06']),
        'airtel-cg' => $op('Airtel Money', ['05', '04']),
    ]),
    'CM' => $c('CM', 'Cameroun', 'CEMAC', '237', 9, 'XAF', '🇨🇲', true, true, 'disbursement', [
        'mtn-cm' => $op('MTN Mobile Money', ['67', '650', '651', '652', '653', '654', '680', '681', '682', '683']),
        'orange-cm' => $op('Orange Money', ['69', '655', '656', '657', '658', '659', '640', '686', '687', '688', '689']),
    ]),
    'GA' => $c('GA', 'Gabon', 'CEMAC', '241', 9, 'XAF', '🇬🇦', true, true, 'remittance', [
        'airtel-ga' => $op('Airtel Money', ['074', '076', '077']),
        'moov-ga' => $op('Moov Money', ['062', '065', '066']),
    ]),
    'TD' => $c('TD', 'Tchad', 'CEMAC', '235', 8, 'XAF', '🇹🇩', true, true, 'remittance', [
        'airtel-td' => $op('Airtel Money', ['6']),
        'moov-td' => $op('Moov Money', ['9']),
    ]),
    'CF' => $c('CF', 'Centrafrique', 'CEMAC', '236', 8, 'XAF', '🇨🇫', true, true, 'remittance', [
        'orange-cf' => $op('Orange Money', ['75']),
        'telecel-cf' => $op('Telecel Cash', ['72', '77']),
        'moov-cf' => $op('Moov Money', ['70']),
    ]),
    'GQ' => $c('GQ', 'Guinée équatoriale', 'CEMAC', '240', 9, 'XAF', '🇬🇶', false, false, 'remittance', [
        'getesa-gq' => $op('GETESA', ['222']),
        'muni-gq' => $op('Muni', ['55']),
    ]),

    // ================================================================ UEMOA (XOF)
    'SN' => $c('SN', 'Sénégal', 'UEMOA', '221', 9, 'XOF', '🇸🇳', true, true, 'remittance', [
        'orange-sn' => $op('Orange Money', ['77', '78']),
        'free-sn' => $op('Free Money', ['76']),
        'expresso-sn' => $op('E-Money (Expresso)', ['70']),
    ]),
    'CI' => $c('CI', "Côte d'Ivoire", 'UEMOA', '225', 10, 'XOF', '🇨🇮', true, true, 'remittance', [
        'orange-ci' => $op('Orange Money', ['07']),
        'mtn-ci' => $op('MTN Mobile Money', ['05']),
        'moov-ci' => $op('Moov Money', ['01']),
    ]),
    'ML' => $c('ML', 'Mali', 'UEMOA', '223', 8, 'XOF', '🇲🇱', true, true, 'remittance', [
        'orange-ml' => $op('Orange Money', ['7', '8']),
        'moov-ml' => $op('Moov Money', ['6', '9']),
    ]),
    'BF' => $c('BF', 'Burkina Faso', 'UEMOA', '226', 8, 'XOF', '🇧🇫', true, true, 'remittance', [
        'orange-bf' => $op('Orange Money', ['05', '06', '07', '54', '55', '56', '57', '64', '65', '66', '67', '74', '75', '76', '77']),
        'moov-bf' => $op('Moov Money', ['01', '02', '03', '51', '52', '53', '60', '61', '62', '63', '70', '71', '72', '73']),
    ]),
    'BJ' => $c('BJ', 'Bénin', 'UEMOA', '229', 10, 'XOF', '🇧🇯', true, true, 'remittance', [
        'mtn-bj' => $op('MTN Mobile Money', array_map(fn ($p) => '01' . $p, ['42', '46', '50', '51', '52', '53', '54', '56', '57', '59', '61', '62', '66', '67', '69', '90', '91', '96', '97'])),
        'moov-bj' => $op('Moov Money', array_map(fn ($p) => '01' . $p, ['55', '58', '60', '63', '64', '65', '68', '94', '95', '98', '99'])),
    ]),
    'TG' => $c('TG', 'Togo', 'UEMOA', '228', 8, 'XOF', '🇹🇬', true, true, 'remittance', [
        'tmoney-tg' => $op('T-Money (Togocom)', ['90', '91', '92', '93', '70', '71', '72']),
        'flooz-tg' => $op('Flooz (Moov)', ['96', '97', '98', '99', '78', '79']),
    ]),
    'NE' => $c('NE', 'Niger', 'UEMOA', '227', 8, 'XOF', '🇳🇪', true, true, 'remittance', [
        'airtel-ne' => $op('Airtel Money', ['96', '97', '98']),
        'moov-ne' => $op('Moov Money', ['94', '95', '99']),
        'zamani-ne' => $op('Zamani Cash', ['90', '91', '92', '93']),
    ]),
    'GW' => $c('GW', 'Guinée-Bissau', 'UEMOA', '245', 9, 'XOF', '🇬🇼', false, true, 'remittance', [
        'orange-gw' => $op('Orange Money', ['95']),
        'mtn-gw' => $op('MTN Mobile Money', ['96']),
    ]),

    // ================================================================ RDC (CDF)
    'CD' => $c('CD', 'RD Congo', 'RDC', '243', 9, 'CDF', '🇨🇩', true, true, 'remittance', [
        'vodacom-cd' => $op('M-Pesa (Vodacom)', ['81', '82', '83']),
        'airtel-cd' => $op('Airtel Money', ['97', '98', '99']),
        'orange-cd' => $op('Orange Money', ['84', '85', '89']),
        'africell-cd' => $op('Afrimoney (Africell)', ['90', '91']),
    ]),

    // ================================================================ Guinée (GNF)
    'GN' => $c('GN', 'Guinée', 'GUINEE', '224', 9, 'GNF', '🇬🇳', true, true, 'remittance', [
        'orange-gn' => $op('Orange Money', ['62']),
        'mtn-gn' => $op('MTN Mobile Money', ['66']),
        'cellcom-gn' => $op('Cellcom', ['65']),
    ]),
];
