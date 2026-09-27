<?php

/*
|--------------------------------------------------------------------------
| Plafonds et paliers KYC (§12 du cahier des charges v1.5)
|--------------------------------------------------------------------------
| Montants INDICATIFS en XAF (1 XAF = 1 XOF), à valider avec la conformité
| COBAC avant mise en production. Un dépassement est BLOQUANT côté API
| (message explicite, HTTP 422, code "limit_exceeded").
|   per_operation : montant max d'une opération sortante
|   daily/monthly : cumul des opérations sortantes (débit du wallet)
|   max_balance   : solde maximal du wallet (null = pas de limite)
| Surcharge possible via Console > Plafonds (table platform_settings, clé "limits").
*/
$e = fn (string $k, $d) => env($k) !== null ? (int) env($k) : $d;

return [
    'enabled' => (bool) env('FLASHPAY_LIMITS_ENABLED', true),

    'client' => [
        // Palier 0 : téléphone + OTP
        0 => ['label' => 'Palier 0 — Téléphone + OTP', 'kyc' => 'Téléphone vérifié par OTP', 'per_operation' => $e('LIMIT_T0_OP', 100000), 'daily' => $e('LIMIT_T0_DAY', 200000), 'monthly' => $e('LIMIT_T0_MONTH', 500000), 'max_balance' => $e('LIMIT_T0_BALANCE', 500000), 'international' => false],
        // Palier 1 : pièce d'identité
        1 => ['label' => 'Palier 1 — Pièce d\'identité', 'kyc' => 'Pièce d\'identité validée', 'per_operation' => $e('LIMIT_T1_OP', 500000), 'daily' => $e('LIMIT_T1_DAY', 1000000), 'monthly' => $e('LIMIT_T1_MONTH', 3000000), 'max_balance' => $e('LIMIT_T1_BALANCE', 2000000), 'international' => false],
        // Palier 2 : KYC complet (pièce + selfie)
        2 => ['label' => 'Palier 2 — KYC complet', 'kyc' => 'Pièce d\'identité + selfie validés', 'per_operation' => $e('LIMIT_T2_OP', 2000000), 'daily' => $e('LIMIT_T2_DAY', 5000000), 'monthly' => $e('LIMIT_T2_MONTH', 20000000), 'max_balance' => $e('LIMIT_T2_BALANCE', 10000000), 'international' => true],
    ],

    // Float agent et encaissement marchand : un seul palier (KYC renforcé à l'activation)
    'agent' => ['label' => 'Agent', 'per_operation' => $e('LIMIT_AGENT_OP', 2000000), 'daily' => $e('LIMIT_AGENT_DAY', 20000000), 'monthly' => $e('LIMIT_AGENT_MONTH', 300000000), 'max_balance' => null],
    'merchant' => ['label' => 'Marchand', 'per_operation' => $e('LIMIT_MERCHANT_OP', 5000000), 'daily' => $e('LIMIT_MERCHANT_DAY', 20000000), 'monthly' => $e('LIMIT_MERCHANT_MONTH', 300000000), 'max_balance' => null],

    // Seuil de déclaration LCB-FT : alerte admin au-delà (opération unique)
    'aml_report_threshold' => $e('FLASHPAY_AML_THRESHOLD', 5000000),

    // Anti-fraude (§4.5) : vélocité et blocage temporaire
    'fraud' => [
        'velocity_window_minutes' => 10,
        'velocity_max_operations' => $e('FLASHPAY_FRAUD_VELOCITY', 10),
        'block_score' => 80,
        'block_minutes' => $e('FLASHPAY_FRAUD_BLOCK_MINUTES', 60),
    ],
];
