<?php

/*
| Notifications par profil (§11). Deux canaux : push in-app (table
| app_notifications + FCM si configuré) et SMS (passerelle SMS/OTP).
*/
return [
    'sms_driver' => env('FLASHPAY_SMS_DRIVER', 'log'), // log | http
    'sms_http' => [
        'url' => env('FLASHPAY_SMS_URL'),
        'token' => env('FLASHPAY_SMS_TOKEN'),
        'sender' => env('FLASHPAY_SMS_SENDER', 'FlashPay'),
    ],
    'push_driver' => env('FLASHPAY_PUSH_DRIVER', 'log'), // log | fcm
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
    ],
    // Événements doublés d'un SMS (opérations sensibles, §11.1)
    'sms_events' => ['otp', 'cash_out', 'pin_changed', 'new_device', 'cash_in', 'voucher_created', 'account_blocked'],
    // Relais email/SMS des alertes critiques admin (§11.4)
    'admin_critical_sms' => env('FLASHPAY_ADMIN_ALERT_PHONE'),
];
