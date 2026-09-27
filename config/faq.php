<?php

/* Base de connaissances in-app (§16). */
return [
    'channels' => [
        ['type' => 'phone', 'label' => 'Centre d\'appel clients', 'value' => env('FLASHPAY_SUPPORT_PHONE', '+242 06 000 00 00'), 'hours' => '7j/7, 7h–21h'],
        ['type' => 'chat', 'label' => 'Chat in-app (tickets)', 'value' => 'Depuis l\'onglet Profil › Aide'],
        ['type' => 'agency', 'label' => 'Agence FlashPay', 'value' => env('FLASHPAY_SUPPORT_ADDRESS', 'Brazzaville, centre-ville')],
        ['type' => 'pro', 'label' => 'Ligne dédiée agents & marchands', 'value' => env('FLASHPAY_PRO_PHONE', '+242 05 000 00 00'), 'hours' => 'Lun–Sam, 7h–20h'],
    ],
    'fr' => [
        ['q' => 'Quels sont mes plafonds ?', 'a' => 'Ils dépendent de votre palier KYC (0 : téléphone, 1 : pièce d\'identité, 2 : pièce + selfie). Consultez Profil › Plafonds. Envoyez vos documents pour les relever.'],
        ['q' => 'Combien de temps pour un virement bancaire ?', 'a' => 'Les virements vers un compte bancaire sont traités sous 24 à 72 h ouvrées.'],
        ['q' => 'Quels sont les frais ?', 'a' => 'Paiement marchand et recharge : gratuits. Envoi national 1 %, régional 2 %, international 3 %. Le détail s\'affiche avant chaque validation.'],
        ['q' => 'J\'ai oublié mon PIN', 'a' => 'Écran de connexion › PIN oublié : code SMS + mot de passe (+ numéro de pièce si enregistré).'],
        ['q' => 'J\'ai perdu mon téléphone ou ma SIM', 'a' => 'Utilisez « Téléphone perdu » sur l\'écran de connexion d\'un autre appareil ou appelez le support : votre compte est bloqué immédiatement.'],
        ['q' => 'Un paiement n\'est pas reconnu', 'a' => 'Ouvrez l\'opération dans l\'historique puis « Contester ». Délai de traitement : 24 à 120 h selon le motif.'],
        ['q' => 'Mon opération est « en cours de vérification »', 'a' => 'L\'opérateur n\'a pas encore confirmé. Aucun double débit n\'est possible : le statut se met à jour automatiquement.'],
    ],
    'en' => [
        ['q' => 'What are my limits?', 'a' => 'They depend on your KYC tier (0: phone, 1: ID document, 2: ID + selfie). See Profile › Limits.'],
        ['q' => 'How long does a bank transfer take?', 'a' => 'Bank transfers are processed within 24 to 72 business hours.'],
        ['q' => 'What are the fees?', 'a' => 'Merchant payments and top-ups are free. Domestic transfer 1 %, regional 2 %, international 3 %.'],
        ['q' => 'I forgot my PIN', 'a' => 'Login screen › Forgot PIN: SMS code + password (+ ID number if registered).'],
        ['q' => 'I lost my phone or SIM', 'a' => 'Use "Lost phone" on the login screen of another device or call support: your account is blocked immediately.'],
        ['q' => 'I don\'t recognise a payment', 'a' => 'Open the transaction in your history and tap "Dispute".'],
    ],
];
