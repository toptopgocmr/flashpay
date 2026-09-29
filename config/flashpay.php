<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rails de paiement actifs
    |--------------------------------------------------------------------------
    | Le FlashPay Switch route chaque transaction vers le bon connecteur
    | en fonction du rail source / rail destination.
    */
    'rails' => [
        // PEEX est l'unique passerelle de paiement externe : MTN, Airtel, Orange,
        // Moov… sont tous collectés / décaissés via PEEX. L'opérateur réel est
        // conservé dans transaction.meta (source_operator / destination_operator).
        'peex' => [
            'driver' => \App\Services\Connectors\PeexConnector::class,
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PEEX — agrégateur collecte / décaissement / remittance
    |--------------------------------------------------------------------------
    | Doc : https://peex-api-docs.peexit.com/  — Auth : header SECRETKEY.
    | Sandbox : le montant réellement initié est fixé à 10 FCFA quel que soit
    | le montant envoyé.
    */
    'peex' => [
        'sandbox' => (bool) env('PEEX_SANDBOX', true),
        'sandbox_url' => env('PEEX_BASE_URL', 'https://sandbox.peexit.com/api/v1/'),
        'production_url' => env('PEEX_PRODUCTION_URL', 'https://server.peexit.com/api/v1/'),
        'secret_key' => env('PEEX_SECRET_KEY', ''),
        'timeout' => (int) env('PEEX_TIMEOUT', 30),

        // Identifiants Basic Auth que PEEX utilise pour appeler NOS callbacks
        'callback_username' => env('PEEX_CALLBACK_USERNAME', 'peex'),
        'callback_password' => env('PEEX_CALLBACK_PASSWORD', 'peex_callback'),

        'default_country' => env('PEEX_DEFAULT_COUNTRY', 'CG'),
        'default_purpose' => 'FAMILY',
        'default_fund_origin' => 'SALARY',
        // Pays d'origine déclaré pour les envois "remittance" (clients/request_payment)
        'sender_country' => env('PEEX_SENDER_COUNTRY', 'CG'),

        // ---- Contrôles avant toute opération via PEEX (PeexGuard) ----
        // Vérifier que chaque numéro mobile money (payeur ET bénéficiaire) est un
        // compte actif, et récupérer le nom du titulaire (Verify Wallet / Get KYC).
        'verify_accounts' => (bool) env('PEEX_VERIFY_ACCOUNTS', true),
        'verify_wallet_path' => env('PEEX_VERIFY_WALLET_PATH', 'clients/verify_wallet'),
        // Vérifier que le service PEEX est activé et que le solde de versement
        // (disbursement_solde / solde) couvre le montant + frais PEEX + encours.
        'check_balance' => (bool) env('PEEX_CHECK_BALANCE', true),
        // Délai après lequel une demande « unknown » introuvable chez PEEX est
        // considérée comme jamais reçue (échec certain -> remboursement).
        'unknown_grace_minutes' => (int) env('PEEX_UNKNOWN_GRACE_MINUTES', 10),
        // Alerte « solde bas » sur le tableau de bord (XAF disponibles par compte de versement)
        'low_balance_alert' => (int) env('PEEX_LOW_BALANCE_ALERT', 100000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Corridors couverts (pays / opérateurs)
    |--------------------------------------------------------------------------
    | - dial : indicatif ; local_length : longueur du numéro national
    |   (Congo et Gabon gardent le 0 initial en format international).
    | - operators : préfixes nationaux -> corridor PEEX + rail FlashPay.
    | - payout_api : API PEEX utilisée pour créditer un numéro de ce pays :
    |     "disbursement" (disbursement/request_payment, champ country)
    |     "remittance"   (clients/request_payment, transfert international)
    | - collect / payout : activation côté FlashPay. L'activation effective
    |   dépend aussi de votre compte PEEX (à confirmer avec support@peexit.com :
    |   la doc publique ne mentionne que le Cameroun comme actif).
    */
    // Pays / opérateurs couverts : voir config/corridors.php
    'corridors' => require __DIR__ . '/corridors.php',

    // Devise des wallets FlashPay créés sans pays identifiable
    'base_currency' => env('FLASHPAY_BASE_CURRENCY', 'XAF'),

    // Zones monétaires à parité fixe (XAF = XOF = 655,957 EUR)
    'fixed_parity' => ['XAF', 'XOF'],

    'tariffs' => [
        // grille tarifaire par défaut, éditable via Super Admin -> table tariffs
        'default_fee_percent' => 1.0,
        'min_fee' => 100, // XAF
        'max_fee' => 5000, // XAF
    ],

    'transaction_limits' => [
        'client_daily_max' => 500000,
        'client_single_max' => 200000,
        'merchant_daily_max' => 5000000,
    ],
];
