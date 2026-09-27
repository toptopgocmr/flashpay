<?php

/*
 * Rôles et habilitations FlashPay (maquettes v2 de l'application mobile).
 * Source unique affichée dans la console (« Rôles & habilitations »).
 * Toute évolution des droits se fait ici ET dans le middleware des routes
 * (routes/api.php : role:client, role:merchant,cashier, role:agent…).
 *
 * Valeurs : 'yes' (autorisé) · ['limited', 'restriction'] · absent = non.
 */
return [
    'capabilities' => [
        'app' => [
            'send' => 'Envoyer de l\'argent (wallet, mobile money, banque)',
            'receive' => 'Recevoir (mon QR, NFC, numéro)',
            'request' => 'Demander de l\'argent à un ami (QR, NFC, numéro)',
            'topup' => 'Recharger son wallet',
            'withdraw' => 'Retirer (agent, mobile money, banque)',
            'pay' => 'Payer un marchand (scan, NFC téléphone ou TPE, code)',
            'collect' => 'Encaisser (QR, NFC, lien de paiement)',
            'scan_client' => 'Scanner le code d\'un client',
            'cash_in' => 'Dépôt d\'espèces pour un client',
            'cash_out' => 'Retrait d\'espèces pour un client',
            'float' => 'Demander un réapprovisionnement de float',
            'sub_agents' => 'Valider le float de ses sous-agents',
            'cashiers' => 'Gérer une équipe de caissiers',
            'settlement' => 'Retrait des fonds (banque / mobile money / agent)',
            'reports' => 'Rapports, historique, remboursements',
            'kyc' => 'Envoyer ses pièces (KYC)',
        ],
        'console' => [
            'desk' => 'Support : tickets, litiges, file KYC',
            'validate' => 'Valider KYC, marchands, agents, float',
            'accounts' => 'Créer agents / marchands, activer / désactiver les comptes',
            'pricing' => 'Tarifs, commissions, corridors, change',
            'ops' => 'Réconciliation, anti-fraude, continuité, audit',
        ],
    ],

    'roles' => [
        'client' => [
            'label' => 'Client', 'space' => 'app', 'tone' => 'blue',
            'created_by' => 'Inscription dans l\'app (2 étapes : compte puis pièce d\'identité)',
            'login' => 'Numéro mobile + code secret (4 chiffres)',
            'grants' => ['send' => 'yes', 'receive' => 'yes', 'request' => 'yes', 'topup' => 'yes', 'withdraw' => 'yes', 'pay' => 'yes', 'kyc' => 'yes',
                'reports' => ['limited', 'Son historique uniquement']],
        ],
        'merchant' => [
            'label' => 'Marchand', 'space' => 'app', 'tone' => 'red',
            'created_by' => 'Inscription dans l\'app (boutique + RCCM) ou Super Admin',
            'login' => 'Numéro mobile + code secret',
            'grants' => ['receive' => 'yes', 'collect' => 'yes', 'scan_client' => 'yes', 'cashiers' => 'yes', 'settlement' => 'yes',
                'reports' => 'yes', 'kyc' => 'yes'],
        ],
        'cashier' => [
            'label' => 'Caissier', 'space' => 'app', 'tone' => 'red',
            'created_by' => 'Le marchand, depuis « Équipe caissiers »',
            'login' => 'Numéro mobile + code secret fourni par le marchand',
            'grants' => ['collect' => ['limited', 'QR dynamique et lien de paiement'], 'reports' => ['limited', 'Ses propres encaissements']],
        ],
        'agent' => [
            'label' => 'Agent', 'space' => 'app', 'tone' => 'blue',
            'created_by' => 'FlashPay (Super Admin)',
            'login' => 'Identifiant agent (AG…) ou numéro + code secret',
            'grants' => ['cash_in' => 'yes', 'cash_out' => 'yes', 'scan_client' => 'yes', 'receive' => 'yes', 'float' => 'yes',
                'send' => ['limited', 'Flux wallet agent ↔ externe (§3.1.5)'], 'topup' => 'yes', 'withdraw' => ['limited', 'Vers ses comptes'],
                'reports' => ['limited', 'Historique et caisse du jour'], 'kyc' => 'yes'],
        ],
        'sub_agent' => [
            'label' => 'Sous-agent', 'space' => 'app', 'tone' => 'blue',
            'created_by' => 'FlashPay, rattaché à un super-agent',
            'login' => 'Identifiant agent ou numéro + code secret',
            'grants' => ['cash_in' => 'yes', 'cash_out' => 'yes', 'scan_client' => 'yes', 'receive' => 'yes',
                'float' => ['limited', 'Validé par son super-agent'], 'reports' => ['limited', 'Historique et caisse du jour'], 'kyc' => 'yes',
                'send' => ['limited', 'Flux wallet agent ↔ externe (§3.1.5)'], 'topup' => 'yes', 'withdraw' => ['limited', 'Vers ses comptes']],
        ],
        'super_agent' => [
            'label' => 'Super-agent', 'space' => 'app', 'tone' => 'red',
            'created_by' => 'FlashPay (agent promu super-agent)',
            'login' => 'Identifiant agent ou numéro + code secret',
            'grants' => ['cash_in' => 'yes', 'cash_out' => 'yes', 'scan_client' => 'yes', 'receive' => 'yes', 'float' => 'yes',
                'sub_agents' => 'yes', 'reports' => 'yes', 'kyc' => 'yes',
                'send' => ['limited', 'Flux wallet agent ↔ externe (§3.1.5)'], 'topup' => 'yes', 'withdraw' => ['limited', 'Vers ses comptes']],
        ],
        'support' => [
            'label' => 'Support', 'space' => 'console', 'tone' => 'blue',
            'created_by' => 'Super Admin (équipe interne)',
            'login' => 'Console FlashPay',
            'grants' => ['desk' => 'yes', 'validate' => ['limited', 'Consultation des pièces, sans décision']],
        ],
        'super_admin' => [
            'label' => 'Super Admin', 'space' => 'console', 'tone' => 'red',
            'created_by' => 'Installation puis Super Admin',
            'login' => 'Console FlashPay',
            'grants' => ['desk' => 'yes', 'validate' => 'yes', 'accounts' => 'yes', 'pricing' => 'yes', 'ops' => 'yes'],
        ],
    ],
];
