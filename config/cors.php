<?php

/*
 * CORS — autorise l'application FlashPay (Flutter web / Chrome en dev) à
 * appeler l'API. Les applis Android / iOS ne sont pas concernées par CORS.
 * Restreindre en production avec CORS_ALLOWED_ORIGINS=https://app.flashpay.cg,…
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '*'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
