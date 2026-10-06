<?php

/*
| Authentification forte (§3.3.1, §4.5, §15)
*/
return [
    // Inscription client : code OTP par SMS obligatoire
    'require_otp_on_register' => (bool) env('FLASHPAY_REQUIRE_OTP', true),
    // PIN applicatif exigé pour toute opération sortante (si false : exigé seulement une fois défini)
    'pin_mandatory' => (bool) env('FLASHPAY_PIN_MANDATORY', true),
    'pin_max_attempts' => 5,
    'pin_lock_minutes' => 30,
    'otp_ttl_minutes' => 5,
    'otp_max_attempts' => 5,
    // En sandbox / local, le code OTP est renvoyé dans la réponse (champ debug_code)
    'otp_debug' => (bool) env('FLASHPAY_OTP_DEBUG', env('APP_DEBUG', false)),
    // Politique multi-appareils : 'single' (nouvelle connexion = déconnexion des autres)
    // ou 'multi' (plusieurs sessions, notification à chaque nouvel appareil)
    'device_policy' => env('FLASHPAY_DEVICE_POLICY', 'multi'),
    // Connexion depuis un appareil inconnu : OTP requis
    'otp_on_new_device' => (bool) env('FLASHPAY_OTP_NEW_DEVICE', true),
    // Comptes de démonstration pour les réviseurs Google Play / Apple : pas d'OTP
    // « nouvel appareil » (ils ne reçoivent pas nos SMS). Numéros séparés par des virgules.
    // Garder un solde faible sur ces comptes ; vider la variable après validation.
    'review_phones' => array_values(array_filter(array_map(
        fn ($p) => preg_replace('/\D+/', '', $p),
        explode(',', (string) env('FLASHPAY_REVIEW_PHONES', ''))
    ))),
    // Confirmation par le client (OTP) d'un dépôt agent saisi par numéro (§3.1.3)
    'cash_in_client_confirmation' => (bool) env('FLASHPAY_CASHIN_CONFIRM', true),
    // E-commerce : clés de production seulement après la recette sandbox (ou validation manuelle)
    'require_sandbox_validation' => (bool) env('FLASHPAY_REQUIRE_SANDBOX_VALIDATION', true),
    // Remboursement en boutique : délai max (jours) après le paiement (§13.2)
    'refund_window_days' => (int) env('FLASHPAY_REFUND_DAYS', 30),
    // QR dynamique : durée de validité (secondes)
    'dynamic_qr_ttl' => (int) env('FLASHPAY_DYNAMIC_QR_TTL', 180),
    // Temps de reprise en cas de timeout opérateur (§13.1) : au-delà → « en cours de vérification »
    'verification_after_minutes' => (int) env('FLASHPAY_VERIFY_AFTER', 15),
    // Objectifs de reprise (§14) — documentaires, exposés par /api/status
    'rto_minutes' => 60,
    'rpo_minutes' => 5,
];
