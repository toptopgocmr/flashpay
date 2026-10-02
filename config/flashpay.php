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
        // Digitwace / WacePay : versements (payout) vers les wallets mobile money,
        // activé pour les pays listés dans DIGITWACE_PAYOUT_COUNTRIES.
        'digitwace' => [
            'driver' => \App\Services\Digitwace\DigitwaceConnector::class,
            'enabled' => (bool) env('DIGITWACE_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Digitwace / WacePay (remittance, 120+ pays)
    |--------------------------------------------------------------------------
    | Clés : tableau de bord WacePay › Developers › API Credentials.
    | La clé privée n'est affichée qu'UNE fois : la mettre uniquement dans les
    | variables d'environnement du serveur (Railway), jamais dans l'app mobile.
    | IP Whitelist : ajouter l'IP publique SORTANTE du serveur (Railway : activer
    | les « Static Outbound IPs »), sinon WacePay répond 4001.
    | Chemins et noms de champs : à aligner sur « Read the docs » du tableau de bord.
    */
    'digitwace' => [
        'enabled' => (bool) env('DIGITWACE_ENABLED', false),
        'base_url' => env('DIGITWACE_BASE_URL', 'https://api.wacepay.com/api/v1/'),
        'public_key' => env('DIGITWACE_PUBLIC_KEY', ''),
        'private_key' => env('DIGITWACE_PRIVATE_KEY', ''),
        'send_api_key_header' => (bool) env('DIGITWACE_SEND_API_KEY_HEADER', false),
        'timeout' => (int) env('DIGITWACE_TIMEOUT', 30),
        'token_ttl_minutes' => (int) env('DIGITWACE_TOKEN_TTL_MINUTES', 55),   // jeton régénéré ~1 fois / heure
        'payer_cache_hours' => (int) env('DIGITWACE_PAYER_CACHE_HOURS', 24),   // getPayerCode 1 fois / jour
        'queue_wait_seconds' => (int) env('DIGITWACE_QUEUE_WAIT_SECONDS', 45), // file unique séquentielle
        // Pays dont les versements passent par WacePay (ISO2, séparés par des virgules ; « * » = tous hors CG)
        'payout_countries' => array_filter(array_map('trim', explode(',', strtoupper((string) env('DIGITWACE_PAYOUT_COUNTRIES', ''))))),
        // payerCode forcés : « CG:MTN=XXXX,CG:AIRTEL=YYYY,SN=ZZZZ » (sinon recherche automatique via getPayerCode)
        'payer_map' => collect(explode(',', (string) env('DIGITWACE_PAYER_CODES', '')))->filter(fn ($p) => str_contains($p, '='))
            ->mapWithKeys(fn ($p) => [strtoupper(trim(explode('=', $p, 2)[0])) => trim(explode('=', $p, 2)[1])])->all(),
        'default_purpose' => env('DIGITWACE_DEFAULT_PURPOSE', 'FAMILY_SUPPORT'),
        // Vérifier le solde WacePay avant chaque versement (bloque si connu et insuffisant)
        'check_balance' => (bool) env('DIGITWACE_CHECK_BALANCE', true),
        // Alerte « solde bas » (XAF / XOF disponibles)
        'low_balance_alert' => (int) env('DIGITWACE_LOW_BALANCE_ALERT', 100000),
        'default_address' => env('DIGITWACE_DEFAULT_ADDRESS', 'Brazzaville'),
        'default_city' => env('DIGITWACE_DEFAULT_CITY', 'Brazzaville'),

        // Webhook entrant : {APP_URL}/api/webhooks/digitwace
        'callback_url' => env('DIGITWACE_CALLBACK_URL'),
        'webhook_secret' => env('DIGITWACE_WEBHOOK_SECRET', ''),
        'signature_header' => env('DIGITWACE_SIGNATURE_HEADER', 'X-Wace-Signature'),
        'webhook_token' => env('DIGITWACE_WEBHOOK_TOKEN', ''),
        'webhook_ips' => env('DIGITWACE_WEBHOOK_IPS', ''),

        // Chemins d'API (relatifs à base_url)
        'paths' => [
            'login' => env('DIGITWACE_PATH_LOGIN', 'auth/login'),
            'create_sender' => env('DIGITWACE_PATH_SENDER', 'sender/create'),
            'create_beneficiary' => env('DIGITWACE_PATH_BENEFICIARY', 'beneficiary/create'),
            'payer_codes' => env('DIGITWACE_PATH_PAYERS', 'payer/codes'),
            'wallet' => env('DIGITWACE_PATH_WALLET', 'transaction/wallet'),
            'confirm' => env('DIGITWACE_PATH_CONFIRM', 'transaction/confirm'),
            'status' => env('DIGITWACE_PATH_STATUS', 'transaction/status'),
            'balance' => env('DIGITWACE_PATH_BALANCE', 'account/balance'),
            // Collecte (PAYIN) : débit du wallet mobile money du client
            'payin' => env('DIGITWACE_PATH_PAYIN', 'payin/mobile'),
        ],

        // Noms des champs envoyés
        'fields' => [
            'country' => 'countryCode',
            'login' => ['public_key' => env('DIGITWACE_FIELD_PUBLIC_KEY', 'apiKey'), 'private_key' => env('DIGITWACE_FIELD_PRIVATE_KEY', 'secretKey')],
            'party' => ['first_name' => 'firstName', 'last_name' => 'lastName', 'phone' => 'phoneNumber', 'country' => 'countryCode', 'address' => 'address', 'city' => 'city'],
            'transaction' => [
                'reference' => 'externalReference', 'sender_code' => 'senderCode', 'beneficiary_code' => 'beneficiaryCode',
                'payer_code' => 'payerCode', 'amount' => 'amount', 'currency' => 'currency', 'wallet_number' => 'walletNumber',
                'purpose' => 'purpose', 'callback_url' => 'callbackUrl', 'transaction_code' => 'transactionCode',
            ],
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
        // true = bloquer quand PEEX ne peut pas vérifier le compte (pays non couvert…)
        'verify_strict' => (bool) env('PEEX_VERIFY_STRICT', false),
        // true = bloquer les versements quand PEEX ne communique pas le solde (disbursement_solde = null)
        'require_payout_balance' => (bool) env('PEEX_REQUIRE_PAYOUT_BALANCE', false),
        // Vérifier que le service PEEX est activé et que le solde de versement
        // (disbursement_solde / solde) couvre le montant + frais PEEX + encours.
        'check_balance' => (bool) env('PEEX_CHECK_BALANCE', true),
        // true : une fiche PEEX (collection/me, disbursement/me) injoignable bloque l'opération
        // Montant minimum accepté par PEEX (en dessous : « Fees is not yet defined »)
        'min_amount' => (int) env('PEEX_MIN_AMOUNT', 100),
        'require_account_check' => (bool) env('PEEX_REQUIRE_ACCOUNT_CHECK', false),
        // Délai après lequel une demande « unknown » introuvable chez PEEX est
        // considérée comme jamais reçue (échec certain -> remboursement).
        'unknown_grace_minutes' => (int) env('PEEX_UNKNOWN_GRACE_MINUTES', 10),
        // Délai max pour valider un paiement mobile money sur le téléphone (code secret).
        // Au-delà : opération « échouée — délai dépassé » (rien n'a été débité) ;
        // une validation tardive confirmée par PEEX rouvre et termine l'opération.
        'validation_timeout_seconds' => (int) env('PEEX_VALIDATION_TIMEOUT_SECONDS', 180),
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
