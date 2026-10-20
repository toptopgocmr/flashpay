<?php

return [
    /*
    | Mentions légales (pages publiques /confidentialite et /suppression-compte,
    | déclarées sur Google Play).
    */
    'legal' => [
        'entity' => env('FLASHPAY_LEGAL_ENTITY', 'SEND-PAZ Group, Brazzaville (République du Congo)'),
        'email' => env('FLASHPAY_SUPPORT_EMAIL'),
        'phone' => env('FLASHPAY_SUPPORT_PHONE'),
        'privacy_updated' => env('FLASHPAY_PRIVACY_UPDATED', '6 octobre 2026'),
    ],

    // Une seule opération d'argent à la fois par utilisateur (middleware one_op)
    'single_operation' => (bool) env('FLASHPAY_SINGLE_OPERATION', true),
    'single_operation_minutes' => (int) env('FLASHPAY_SINGLE_OPERATION_MINUTES', 10),

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
        // Transferts internationaux (remittance) : WacePay API v2 (wallet, banque, retrait cash)
        // et TerraPay (wallet, banque). Versement uniquement : la source est le wallet FlashPay.
        'wacepay_remit' => [
            'driver' => \App\Services\Remit\WacepayRemitConnector::class,
            'enabled' => (bool) env('WACEPAY_REMIT_ENABLED', false),
        ],
        'terrapay' => [
            'driver' => \App\Services\Remit\TerrapayConnector::class,
            'enabled' => (bool) env('TERRAPAY_ENABLED', false),
        ],
        'pawapay' => [
            'driver' => \App\Services\Remit\PawapayConnector::class,
            'enabled' => (bool) env('PAWAPAY_ENABLED', false),
        ],
        // pawaPay passerelle nationale : collecte (deposits) et versements (payouts) mobile money
        // pour les pays confiés à pawaPay dans la console (Pays & change), ex. MTN / Airtel Congo.
        'pawapay_momo' => [
            'driver' => \App\Services\Remit\PawapayMomoConnector::class,
            'enabled' => (bool) env('PAWAPAY_ENABLED', false),
        ],
        // MaishaPay : collecte (mobile money, carte) et versements mobile money (B2C)
        'maishapay' => [
            'driver' => \App\Services\Maishapay\MaishapayConnector::class,
            'enabled' => (bool) env('MAISHAPAY_ENABLED', false),
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
    /*
    |--------------------------------------------------------------------------
    | Transferts internationaux (remittance) — WacePay API v2 et TerraPay
    |--------------------------------------------------------------------------
    | Types : P2P (particulier → particulier), P2B (particulier → entreprise),
    | B2P (entreprise → particulier), B2B (entreprise → entreprise).
    | Moyens de réception : wallet mobile, compte bancaire, retrait cash (WacePay).
    | La source est toujours le wallet FlashPay du client (débité montant + frais).
    */
    'remit' => [
        // Partenaire par pays de destination : « FR=terrapay,CI=wacepay_remit,*=wacepay_remit »
        'partners' => collect(explode(',', (string) env('REMIT_PARTNERS', '*=wacepay_remit')))->filter(fn ($p) => str_contains($p, '='))
            ->mapWithKeys(fn ($p) => [strtoupper(trim(explode('=', $p, 2)[0])) => strtolower(trim(explode('=', $p, 2)[1]))])->all(),
        // Pays de destination proposés dans l'app (vide = catalogue complet de RemitCountries)
        'countries' => array_filter(array_map('trim', explode(',', strtoupper((string) env('REMIT_COUNTRIES', ''))))),
        // Niveau KYC minimum pour envoyer à l'international
        'min_kyc_tier' => (int) env('REMIT_MIN_KYC_TIER', 1),
        // Profil « Activer l'international » : pièces (recto / verso / selfie) envoyées exigées
        'require_documents' => (bool) env('REMIT_REQUIRE_DOCUMENTS', true),
        // E-mail vérifié par code exigé (activer quand l'envoi d'e-mails est configuré : MAIL_MAILER…)
        'require_email_verified' => (bool) env('REMIT_REQUIRE_EMAIL_VERIFIED', false),
        // Code postal de l'expéditeur exigé pour ces pays de destination (TerraPay)
        'postal_code_countries' => array_filter(array_map('trim', explode(',', strtoupper((string) env('REMIT_POSTAL_CODE_COUNTRIES', 'US,CA,GB'))))),
        // Identité complète du bénéficiaire exigée par TerraPay pour ces pays (date de naissance, pièce, adresse)
        'beneficiary_kyc_countries' => array_filter(array_map('trim', explode(',', strtoupper((string) env('REMIT_BENEFICIARY_KYC_COUNTRIES', 'IN,PK,BD,PH,NP,LK,CN'))))),
        'min_amount' => (int) env('REMIT_MIN_AMOUNT', 1000),
        'max_amount' => (int) env('REMIT_MAX_AMOUNT', 5000000),
        // Pays / devise d'envoi (FlashPay)
        'from_country' => strtoupper((string) env('REMIT_FROM_COUNTRY', 'CG')),
        'from_currency' => strtoupper((string) env('REMIT_FROM_CURRENCY', 'XAF')),
    ],

    'wacepay_remit' => [
        'enabled' => (bool) env('WACEPAY_REMIT_ENABLED', false),
        // Sandbox : https://sandbox.wacepay.com/api/v1 — production : à fournir par WacePay
        'base_url' => env('WACEPAY_REMIT_BASE_URL', 'https://sandbox.wacepay.com/api/v1'),
        // Couple e-mail / mot de passe fourni par WacePay (variables Railway uniquement, jamais dans Git)
        // (repris de DIGITWACE_EMAIL / DIGITWACE_PASSWORD si c'est sous ces noms qu'ils ont été saisis)
        'email' => env('WACEPAY_REMIT_EMAIL', env('DIGITWACE_EMAIL', '')),
        'password' => env('WACEPAY_REMIT_PASSWORD', env('DIGITWACE_PASSWORD', '')),
        'timeout' => (int) env('WACEPAY_REMIT_TIMEOUT', 40),
        'token_ttl_minutes' => (int) env('WACEPAY_REMIT_TOKEN_TTL', 55), // jeton valable 3600 s
        // Confirmer automatiquement les transactions « WAITING CONFIRMATION » (POST /transaction/confirm)
        'auto_confirm' => (bool) env('WACEPAY_REMIT_AUTO_CONFIRM', true),
        'check_balance' => (bool) env('WACEPAY_REMIT_CHECK_BALANCE', true),
        'default_civility' => env('WACEPAY_REMIT_DEFAULT_CIVILITY', 'Single'),
        'default_address' => env('WACEPAY_REMIT_DEFAULT_ADDRESS', 'Brazzaville'),
    ],

    /*
    | Bascule automatique du versement mobile money : si le partenaire du pays refuse
    | (échec certain, rien versé), FlashPay réessaie chez un autre partenaire du même
    | environnement (production ↔ production) qui couvre le pays.
    */
    /*
    | Frais partenaires (collecte + versement) ajoutés au total payé par le client, d'après les
    | tarifs de la console (Tarifs › frais partenaires). false = FlashPay les absorbe.
    */
    'fees' => [
        'pass_partner_fees' => (bool) env('FLASHPAY_PASS_PARTNER_FEES', true),
    ],

    'failover' => [
        'enabled' => (bool) env('FLASHPAY_FAILOVER', true),
        'order' => array_values(array_filter(array_map('trim', explode(',', (string) env('FLASHPAY_FAILOVER_ORDER', 'peex,pawapay_momo,digitwace,maishapay'))))),
        'max_switches' => (int) env('FLASHPAY_FAILOVER_MAX', 2),
        // Mode de routage des pays sans réglage console : auto | mixed | fixed
        'default_mode' => env('FLASHPAY_ROUTING_DEFAULT', 'auto'),
        // Bascule aussi de la COLLECTE quand l'API rejette la demande (rien n'est débité)
        'collect' => (bool) env('FLASHPAY_FAILOVER_COLLECT', true),
        // Remboursement mobile money impossible → montant + frais recrédités sur le wallet FlashPay du client
        'refund_to_wallet' => (bool) env('FLASHPAY_REFUND_TO_WALLET', true),
        // Remboursement sur le compte débité par le partenaire qui a collecté (WacePay, PawaPay ; PEEX toujours)
        'refund_to_source' => (bool) env('FLASHPAY_REFUND_TO_SOURCE', true),
    ],

    'pawapay' => [
        'enabled' => (bool) env('PAWAPAY_ENABLED', false),
        // Sandbox : https://api.sandbox.pawapay.io — production : https://api.pawapay.io
        'base_url' => env('PAWAPAY_BASE_URL', 'https://api.sandbox.pawapay.io'),
        // Jeton API (tableau de bord pawaPay › API Tokens) — variables Railway uniquement
        'token' => env('PAWAPAY_API_TOKEN', ''),
        // remittance = API Remittances (KYC expéditeur, transferts internationaux) ; payout = API Payouts
        'mode' => env('PAWAPAY_MODE', 'remittance'),
        'balances_path' => env('PAWAPAY_BALANCES_PATH', 'v2/wallet-balances'),
        'timeout' => (int) env('PAWAPAY_TIMEOUT', 40),
        // Passerelle nationale : solde pawaPay vérifié avant chaque versement
        'check_balance' => (bool) env('PAWAPAY_CHECK_BALANCE', true),
        // Collecte / versement inconnus chez pawaPay après ce délai : jamais arrivés → échec (remboursement)
        'not_found_minutes' => (int) env('PAWAPAY_NOT_FOUND_MINUTES', 2),
        // Callbacks (à saisir dans le tableau de bord pawaPay) : {APP_URL}/api/webhooks/pawapay
    ],

    /*
    |--------------------------------------------------------------------------
    | MaishaPay (https://docs.maishapay.net) — RDC, Congo, Cameroun, Sénégal, Bénin, Côte d'Ivoire, Togo
    |--------------------------------------------------------------------------
    | Clés : tableau de bord marchand https://marchand.maishapay.online (MP-SBPK-… en sandbox,
    | MP-LIVEPK-… en production). Variables Railway uniquement, jamais dans l'app.
    | Callback (collecte, versement, carte) : {APP_URL}/api/webhooks/maishapay
    */
    'maishapay' => [
        'enabled' => (bool) env('MAISHAPAY_ENABLED', false),
        'base_url' => env('MAISHAPAY_BASE_URL', 'https://marchand.maishapay.online/api'),
        'public_key' => env('MAISHAPAY_PUBLIC_KEY', ''),
        'secret_key' => env('MAISHAPAY_SECRET_KEY', ''),
        // gatewayMode : 0 = sandbox, 1 = production (déduit de la clé MP-LIVEPK-… si non renseigné)
        'sandbox' => env('MAISHAPAY_SANDBOX') === null ? null : filter_var(env('MAISHAPAY_SANDBOX'), FILTER_VALIDATE_BOOLEAN),
        // Jeton Bearer facultatif (doc Web API v2.1) ; les clés sont toujours envoyées dans le corps
        'bearer' => env('MAISHAPAY_BEARER_TOKEN', ''),
        // Secret de signature des webhooks (X-MaishaPay-Signature : sha256=HMAC du corps) — facultatif
        'webhook_secret' => env('MAISHAPAY_WEBHOOK_SECRET', ''),
        'timeout' => (int) env('MAISHAPAY_TIMEOUT', 40),
        // Pays où MaishaPay est proposé dans la console (Pays & change)
        'countries' => array_filter(array_map('trim', explode(',', strtoupper((string) env('MAISHAPAY_COUNTRIES', 'CD,CG,CM,SN,BJ,CI,TG'))))),
        // Chemins (collection Postman MaishaPay) — ajustables sans redéploiement du code
        'paths' => [
            'collect_mobile' => env('MAISHAPAY_PATH_COLLECT_MOBILE', 'collect/v2/store/mobileMoney'),
            'collect_card' => env('MAISHAPAY_PATH_COLLECT_CARD', 'collect/v3/store/card'),
            // Crypto Money : dossier Postman encore vide — chemin à confirmer par MaishaPay
            'collect_crypto' => env('MAISHAPAY_PATH_COLLECT_CRYPTO', 'collect/v3/store/crypto'),
            'payout_mobile' => env('MAISHAPAY_PATH_PAYOUT_MOBILE', 'b2c/store/transfert/mobilemoney'),
            'check' => env('MAISHAPAY_PATH_CHECK', 'transaction/rest/v2/check'),
            // Services (doc Web API v2.1, à confirmer avec MaishaPay)
            'airtime' => env('MAISHAPAY_PATH_AIRTIME', 'services/airtime/topup'),
            'paytv' => env('MAISHAPAY_PATH_PAYTV', 'services/paytv/pay'),
            'utility' => env('MAISHAPAY_PATH_UTILITY', 'services/utility/pay'),
        ],
        // Opération inconnue chez MaishaPay après ce délai : jamais arrivée → échec
        'not_found_minutes' => (int) env('MAISHAPAY_NOT_FOUND_MINUTES', 2),
    ],

    // Services payés depuis le wallet (crédit, TV, factures) — exécutés par MaishaPay
    'bills' => [
        'enabled' => (bool) env('FLASHPAY_BILLS_ENABLED', true),
        // Moyen de paiement déclaré à MaishaPay pour un abonnement TV payé par FlashPay (à confirmer avec MaishaPay)
        'payment_method' => env('MAISHAPAY_SERVICES_PAYMENT_METHOD', 'balance'),
    ],

    'terrapay' => [
        'enabled' => (bool) env('TERRAPAY_ENABLED', false),
        // UAT : https://uat-connect.terrapay.com:21211 — production : URL fournie par TerraPay
        'base_url' => env('TERRAPAY_BASE_URL', 'https://uat-connect.terrapay.com:21211'),
        'username' => env('TERRAPAY_USERNAME', ''),
        // Mot de passe en clair (haché SHA-256 à l'envoi) ou déjà haché si TERRAPAY_PASSWORD_HASHED=true
        'password' => env('TERRAPAY_PASSWORD', ''),
        'password_hashed' => (bool) env('TERRAPAY_PASSWORD_HASHED', false),
        'origin_country' => strtoupper((string) env('TERRAPAY_ORIGIN_COUNTRY', env('REMIT_FROM_COUNTRY', 'CG'))),
        // Devise de règlement avec TerraPay (devis « sendingCurrency ») ; vide = devise du wallet (XAF)
        'sending_currency' => env('TERRAPAY_SENDING_CURRENCY'),
        'timeout' => (int) env('TERRAPAY_TIMEOUT', 40),
        // Vérifier le compte du bénéficiaire (wallet / banque) avant de débiter le client
        'verify_account' => (bool) env('TERRAPAY_VERIFY_ACCOUNT', true),
        // Chemin du statut d'une transaction (standard GSMA) — à ajuster si TerraPay indique un autre chemin
        'status_path' => env('TERRAPAY_STATUS_PATH', 'eig/gsma/transactions/{ref}'),
        // Valeur « type » de la transaction (inttransfer par défaut ; p2p, b2p… si TerraPay les active)
        'types' => [
            'p2p' => env('TERRAPAY_TYPE_P2P', 'inttransfer'),
            'p2b' => env('TERRAPAY_TYPE_P2B', 'inttransfer'),
            'b2p' => env('TERRAPAY_TYPE_B2P', 'inttransfer'),
            'b2b' => env('TERRAPAY_TYPE_B2B', 'inttransfer'),
        ],
    ],

    'digitwace' => [
        'enabled' => (bool) env('DIGITWACE_ENABLED', false),
        // Versement déclaré introuvable par WacePay au-delà de ce délai → échec + remboursement
        'payout_not_found_seconds' => (int) env('DIGITWACE_PAYOUT_NOT_FOUND_SECONDS', 120),
        // Qui paie la commission de collecte WacePay : « payer » = WacePay l'ajoute au débit du client
        // (constaté en sandbox : feeBearer PAYER) ; « merchant » = déduite de ce que FlashPay reçoit.
        'collect_fee_bearer' => env('DIGITWACE_COLLECT_FEE_BEARER', 'payer'),
        // Doc : https://docs.digitwace.com — API PayIn : https://payinws.wacepay.com/api/v1
        // API Partenaire : sandbox https://sandbox-payinws.wacepay.io/api/v1/ (tableau de bord sandbox-payin.wacepay.io)
        'base_url' => env('DIGITWACE_BASE_URL', env('DIGITWACE_SANDBOX', true) ? 'https://sandbox-payinws.wacepay.io/api/v1/' : 'https://payinws.wacepay.io/api/v1/'),
        // basic = GET get-token + Authorization: Basic base64(public_key:private_key) (doc PayIn)
        // login = POST login avec les clés dans le corps (API Business)
        'auth_mode' => env('DIGITWACE_AUTH_MODE', 'basic'),
        // partner = API Partenaire WacePay (payments/* et payout/*, collection Postman oct. 2026)
        // legacy  = ancienne API Business (sender / beneficiary / wallet / confirm)
        'api' => env('DIGITWACE_API', 'partner'),
        // E-mail client envoyé à payments/create quand le client n'en a pas
        'default_email' => env('DIGITWACE_DEFAULT_EMAIL', 'clients@flashpay.cg'),
        // Services WacePay (wp-subscription-key) pour la collecte par carte / compte bancaire.
        // Vide = détection automatique dans la couverture synchronisée (nom VISA, CARD, BANK…).
        'card_service_id' => env('DIGITWACE_CARD_SERVICE_ID'),
        'bank_service_id' => env('DIGITWACE_BANK_SERVICE_ID'),
        // Collecte WacePay refusée (rien n'est prélevé) : nouvel essai automatique via PEEX.
        // Désactivé par défaut : la passerelle choisie dans la console gère TOUS les flux du pays.
        'collect_fallback_peex' => (bool) env('DIGITWACE_COLLECT_FALLBACK_PEEX', false),
        // Chemins de l'API Partenaire à surcharger si WacePay les change (sinon valeurs de DigitwaceClient::PARTNER_PATHS)
        'partner_paths' => array_filter([
            'login' => env('DIGITWACE_PARTNER_PATH_LOGIN'),
            'payin' => env('DIGITWACE_PARTNER_PATH_PAYIN'),
            'payout' => env('DIGITWACE_PARTNER_PATH_PAYOUT'),
            'balance' => env('DIGITWACE_PARTNER_PATH_BALANCE'),
        ]),
        'payer_codes_method' => env('DIGITWACE_PAYER_CODES_METHOD', 'post'),
        'payout_service' => env('DIGITWACE_PAYOUT_SERVICE', 'WALLET'),
        'origin_fund' => env('DIGITWACE_ORIGIN_FUND', 'SALARY'),
        'relation' => env('DIGITWACE_RELATION', 'FRIEND'),
        // Adresses supplémentaires à essayer par la détection automatique (séparées par des virgules, *.wacepay.com)
        'discover_bases' => env('DIGITWACE_DISCOVER_BASES', ''),
        // Environnement WacePay, indépendant de PEEX (PEEX_SANDBOX) : true = sandbox / test
        'sandbox' => (bool) env('DIGITWACE_SANDBOX', true),
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
            'login' => env('DIGITWACE_PATH_LOGIN', 'get-token'),
            'create_sender' => env('DIGITWACE_PATH_SENDER', 'sender/create'),
            'create_beneficiary' => env('DIGITWACE_PATH_BENEFICIARY', 'beneficiary/create'),
            'payer_codes' => env('DIGITWACE_PATH_PAYERS', 'transaction/payercode'),
            'wallet' => env('DIGITWACE_PATH_WALLET', 'transaction/wallet/create'),
            'confirm' => env('DIGITWACE_PATH_CONFIRM', 'transaction/confirm'),
            'status' => env('DIGITWACE_PATH_STATUS', 'transaction/status/{ref}'),
            'balance' => env('DIGITWACE_PATH_BALANCE', 'account/balance'),
            // Collecte (PAYIN) : débit du wallet mobile money du client
            'payin' => env('DIGITWACE_PATH_PAYIN', 'create'),
            // Collecte par carte Visa / Mastercard et par compte bancaire : page de paiement WacePay (3-D Secure / banque)
            'payin_card' => env('DIGITWACE_PATH_PAYIN_CARD', 'payin/card'),
            'payin_bank' => env('DIGITWACE_PATH_PAYIN_BANK', 'payin/bank'),
        ],

        // Noms des champs envoyés
        'fields' => [
            'country' => 'countryCode',
            'login' => ['public_key' => env('DIGITWACE_FIELD_PUBLIC_KEY', 'apiKey'), 'private_key' => env('DIGITWACE_FIELD_PRIVATE_KEY', 'secretKey')],
            'party' => ['first_name' => 'firstName', 'last_name' => 'lastName', 'phone' => 'phone', 'country' => 'country', 'address' => 'address', 'city' => 'city'],
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
        'validation_timeout_seconds' => (int) env('PEEX_VALIDATION_TIMEOUT_SECONDS', 120),
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
    // Affichage dans la monnaie du pays du client (EUR, USD…) : solde tenu en devise du wallet, montants convertis
    'display_currency' => ['enabled' => (bool) env('FLASHPAY_DISPLAY_CURRENCY', true)],
    // Devises possibles pour un wallet ; un pays dans une autre devise (EUR, USD…) a un wallet en devise de base
    'wallet_currencies' => array_filter(array_map('trim', explode(',', strtoupper((string) env('FLASHPAY_WALLET_CURRENCIES', 'XAF,XOF,CDF,GNF'))))),

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

    // Appels audio du chat (WebRTC) : STUN gratuits par défaut ; un serveur TURN
    // (ex. coturn, Metered, Twilio) améliore la connexion sur les réseaux 4G stricts.
    'webrtc' => [
        'stun_urls' => env('WEBRTC_STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'),
        // Relais TURN indispensable quand les deux téléphones sont en 4G (NAT opérateur) :
        // par défaut le relais public gratuit « Open Relay » (Metered) ; pour la production,
        // créez un compte TURN (Metered, Twilio, coturn…) et renseignez WEBRTC_TURN_* .
        // WEBRTC_TURN_URL=none désactive le relais.
        'turn_url' => env('WEBRTC_TURN_URL', 'turn:openrelay.metered.ca:80,turn:openrelay.metered.ca:443,turn:openrelay.metered.ca:443?transport=tcp'),
        'turn_username' => env('WEBRTC_TURN_USERNAME', 'openrelayproject'),
        'turn_password' => env('WEBRTC_TURN_PASSWORD', 'openrelayproject'),
        // Relais TURN « clé en main » (recommandé en production) : identifiants temporaires générés par le serveur
        'cloudflare_key_id' => env('WEBRTC_CLOUDFLARE_TURN_KEY_ID'),
        'cloudflare_token' => env('WEBRTC_CLOUDFLARE_TURN_TOKEN'),
        'metered_domain' => env('WEBRTC_METERED_DOMAIN'),
        'metered_api_key' => env('WEBRTC_METERED_API_KEY'),
    ],

    // Chat traduit automatiquement (chacun lit dans sa langue, comme Alibaba).
    'translation' => [
        'enabled' => (bool) env('CHAT_TRANSLATION', true),
        'driver' => env('CHAT_TRANSLATION_DRIVER', 'mymemory'), // mymemory | google | deepl
        'mymemory_email' => env('MYMEMORY_EMAIL'),              // quota gratuit porté à 50 000 caractères/jour
        'google_key' => env('GOOGLE_TRANSLATE_KEY'),
        'deepl_key' => env('DEEPL_KEY'),
    ],
];
