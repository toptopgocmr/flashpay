<?php

/*
|--------------------------------------------------------------------------
| Moyens de recharge / retrait / paiement proposés PAR PAYS
|--------------------------------------------------------------------------
| L'app appelle GET /api/pay/methods?country=CG&operation=deposit|withdraw|pay
| et affiche les moyens renvoyés (disponibles ou « bientôt disponible »).
|
| - countries : '*' (tous les pays des corridors) ou liste ISO séparée par des
|   virgules, surchargeable par .env (ex. FLASHPAY_AGENT_COUNTRIES=CG,CM).
| - mobile_money : les opérateurs proposés viennent de config/corridors.php
|   (MTN, Airtel, Orange, Moov, Free, Wave…) et de l'activation PEEX du pays
|   (collect pour la recharge, payout pour le retrait).
| - atm (GAB) : nécessite un accord avec une banque partenaire. Désactivé tant
|   que FLASHPAY_ATM_COUNTRIES est vide ; le moyen s'affiche alors « bientôt ».
*/

$list = fn (string $env, string $default) => array_values(array_filter(array_map(
    'trim', explode(',', strtoupper((string) env($env, $default)))
)));

return [
    // Pays où FlashPay dispose d'un réseau d'agents (cash-in / cash pickup)
    'agent_countries' => $list('FLASHPAY_AGENT_COUNTRIES', 'CG'),

    // Pays où le retrait au GAB est ouvert (banque partenaire signée)
    'atm_countries' => $list('FLASHPAY_ATM_COUNTRIES', ''),
    'atm_partner_key' => env('FLASHPAY_ATM_PARTNER_KEY'),

    // Carte Visa / Mastercard (prépayée ou bancaire) : passerelle carte (PSP).
    // sandbox = page de paiement simulée (aucune carte réelle débitée) ; none = « bientôt ».
    'card_driver' => env('FLASHPAY_CARD_DRIVER', filter_var(env('PEEX_SANDBOX', true), FILTER_VALIDATE_BOOLEAN) ? 'sandbox' : 'none'),
    'card_countries' => $list('FLASHPAY_CARD_COUNTRIES', '*'),

    // Prélèvement sur compte bancaire (recharge du wallet) : wacepay | sandbox | none
    'bank_debit_driver' => env('FLASHPAY_BANK_DEBIT_DRIVER', 'none'),
    'bank_debit_countries' => $list('FLASHPAY_BANK_DEBIT_COUNTRIES', '*'),

    // Pays où les virements vers un compte bancaire sont ouverts (banque partenaire)
    'bank_countries' => $list('FLASHPAY_BANK_COUNTRIES', ''),

    // Bons de retrait (cash pickup / GAB)
    'voucher_ttl_hours' => (int) env('FLASHPAY_VOUCHER_TTL_HOURS', 72),
    'voucher_max_amount' => (int) env('FLASHPAY_VOUCHER_MAX', 500000),
    'agent_commission_percent' => (float) env('FLASHPAY_AGENT_COMMISSION', 50), // % des frais reversés à l'agent

    // Code de paiement client (style Alipay / WeChat Pay)
    'pay_code_ttl_seconds' => (int) env('FLASHPAY_PAY_CODE_TTL', 120),
    'pay_code_max_amount' => (int) env('FLASHPAY_PAY_CODE_MAX', 200000),

    'operations' => [
        'deposit' => [
            'mobile_money' => ['label' => 'Mobile money', 'description' => 'Depuis votre compte {operators}. Validation avec votre code secret.', 'icon' => 'phone', 'needs' => 'collect'],
            'card' => ['label' => 'Carte Visa / Mastercard', 'description' => 'Carte prépayée ou bancaire. Paiement sécurisé 3-D Secure.', 'icon' => 'card', 'needs' => 'card'],
            'bank' => ['label' => 'Depuis un compte bancaire', 'description' => 'Prélèvement sur votre compte en banque, validé sur la page sécurisée de la banque.', 'icon' => 'bank', 'needs' => 'bank_debit'],
            'agent_qr' => ['label' => 'Espèces chez un agent', 'description' => 'Montrez votre QR FlashPay à un agent et remettez-lui les espèces.', 'icon' => 'store', 'needs' => 'agents'],
        ],
        'withdraw' => [
            'mobile_money' => ['label' => 'Vers mobile money', 'description' => 'Vers votre compte {operators}.', 'icon' => 'phone', 'needs' => 'payout'],
            'cash_pickup' => ['label' => 'Retrait cash chez un agent', 'description' => 'Recevez un code de retrait, présentez-le à un agent FlashPay.', 'icon' => 'cash', 'needs' => 'agents'],
            'bank' => ['label' => 'Vers un compte bancaire', 'description' => 'Virement vers votre compte en banque.', 'icon' => 'bank', 'needs' => 'bank'],
            'atm' => ['label' => 'Retrait au GAB', 'description' => 'Retirez sans carte au distributeur avec un code FlashPay.', 'icon' => 'atm', 'needs' => 'atm'],
        ],
        'pay' => [
            'scan_qr' => ['label' => 'Scanner le QR du marchand', 'description' => 'Scannez le QR affiché en caisse.', 'icon' => 'scan', 'needs' => null],
            'nfc' => ['label' => 'Sans contact (NFC / TPE)', 'description' => 'Approchez votre téléphone du TPE ou de l\'autocollant NFC du marchand.', 'icon' => 'nfc', 'needs' => null],
            'pay_code' => ['label' => 'Montrer mon code de paiement', 'description' => 'Le marchand scanne votre code, le montant est débité de votre wallet.', 'icon' => 'qr', 'needs' => null],
            'mobile_money' => ['label' => 'Payer avec mobile money', 'description' => 'Payez un marchand FlashPay depuis {operators}.', 'icon' => 'phone', 'needs' => 'collect'],
        ],
        // Envoyer : sources (wallet, mobile money, carte) et destinations (wallet, mobile money, banque)
        'send' => [
            'wallet' => ['label' => 'Wallet FlashPay', 'description' => 'Instantané entre comptes FlashPay.', 'icon' => 'wallet', 'needs' => null],
            'mobile_money' => ['label' => 'Mobile money', 'description' => '{operators} et opérateurs des autres pays.', 'icon' => 'phone', 'needs' => 'payout'],
            'card' => ['label' => 'Carte Visa / Mastercard', 'description' => 'Payer l\'envoi par carte.', 'icon' => 'card', 'needs' => 'card'],
            'bank' => ['label' => 'Compte bancaire', 'description' => 'Virement vers un compte en banque.', 'icon' => 'bank', 'needs' => 'bank'],
        ],
    ],
];
