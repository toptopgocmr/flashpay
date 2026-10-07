<?php

namespace App\Services\Digitwace;

use App\Models\DigitwaceParty;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP Digitwace / WacePay (remittance, plus de 120 pays).
 *
 * Parcours d'une transaction (doc « Supported load by Wacepay APIs ») :
 *   1. Login token        → mis en cache 55 min (l'API de login est limitée en débit)
 *   2. Create Sender      → senderCode mémorisé (table digitwace_parties)
 *   3. Create Beneficiary → beneficiaryCode mémorisé
 *   4. Get Payer Code     → mis en cache 24 h (change très rarement)
 *   5. Wallet             → création de la transaction vers le wallet mobile
 *   6. Confirm            → confirmation
 * Avec expéditeur / bénéficiaire connus et payerCode en cache : 2 requêtes
 * seulement (Wallet + Confirm). Les transactions passent une par une dans une
 * file unique (verrou) — recommandation WacePay.
 *
 * Les chemins d'API et les noms de champs sont dans config/flashpay.php
 * (section « digitwace ») : à ajuster selon la doc « Read the docs » du
 * tableau de bord WacePay sans toucher au code.
 *
 * Codes retour WacePay : 2000 = succès ; 1001-1017 = données / règles invalides ;
 * 2001, 3001 = erreur système ; 3002 = utilisateur ou solde inactif ;
 * 3015-3016 = référence invalide / déjà utilisée ; 4001 = accès refusé (IP non autorisée…).
 */
class DigitwaceClient
{
    public const OK = '2000';

    /** Chemins de l'API Partenaire WacePay (collection Postman « Wacepay Partner API », oct. 2026). */
    public const PARTNER_PATHS = [
        'login' => 'payments/get-token',
        'payin_services' => 'payments/services',
        'countries' => 'payments/countries',
        'payin' => 'payments/create',
        'payin_status' => 'payments/check-status',
        'payout_services' => 'payout/services',
        'payout' => 'payout/execute',
        'payout_list' => 'payout/transactions',
        'payout_tx' => 'payout/transactions/{id}',
        'payout_refresh' => 'payout/refresh-status/{id}',
        'balance' => 'payout/balance/history',
    ];

    /** API Partenaire (défaut) ou ancienne API « Business » (sender / beneficiary / wallet / confirm). */
    public function partner(): bool
    {
        return (string) $this->cfg('api', 'partner') !== 'legacy';
    }

    public function enabled(): bool
    {
        return (bool) $this->cfg('enabled') && $this->cfg('public_key') && $this->cfg('private_key');
    }

    // ------------------------------------------------------------ Authentification

    public function token(bool $fresh = false): string
    {
        $key = 'digitwace:token:' . md5((string) $this->cfg('public_key'));
        if (! $fresh && ($t = Cache::get($key))) {
            return $t;
        }

        // Un seul login à la fois (rate limiter côté WacePay)
        return Cache::lock('digitwace:login', 20)->block(15, function () use ($key, $fresh) {
            if (! $fresh && ($t = Cache::get($key))) {
                return $t;
            }
            $res = $this->loginRequest();
            $json = $res->json() ?? [];
            $token = self::tokenFrom($json);
            if (! $res->successful() || ! $token) {
                Log::warning('WacePay connexion refusée', ['http' => $res->status(), 'url' => $this->cfg('base_url') . $this->path('login'), 'body' => mb_substr((string) $res->body(), 0, 300)]);
                throw new DigitwaceException('Connexion WacePay refusée : ' . $this->errorText($json, $res->status()), $this->codeOf($json));
            }
            Cache::put($key, $token, now()->addMinutes((int) $this->cfg('token_ttl_minutes', 55)));
            return $token;
        });
    }

    /**
     * Connexion WacePay (doc docs.digitwace.com) :
     *  - mode « basic » (défaut, API PayIn) : GET {base}/get-token avec
     *    Authorization: Basic base64(public_key:private_key) → jeton Bearer ;
     *  - mode « login » (API Business) : POST {base}/login avec les clés dans le corps.
     */
    protected function loginRequest(?string $base = null, ?string $path = null, ?string $mode = null)
    {
        $pub = (string) $this->cfg('public_key');
        $priv = (string) $this->cfg('private_key');
        $mode ??= $this->partner() ? 'basic' : (string) $this->cfg('auth_mode', 'basic');
        $http = $base ? Http::baseUrl($base)->acceptJson()->timeout(20) : $this->http();
        $path = ltrim($path ?? $this->path('login'), '/');
        if ($mode === 'basic') {
            return $http->withHeaders(['Authorization' => 'Basic ' . base64_encode($pub . ':' . $priv)])->get($path);
        }
        $f = $this->cfg('fields.login');
        return $http->post($path, [$f['public_key'] => $pub, $f['private_key'] => $priv]);
    }

    public static function tokenFrom(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }
        foreach (['token', 'access_token', 'accessToken', 'bearer', 'bearer_token', 'BEARER_TOKEN', 'jwt'] as $k) {
            foreach ([$k, "data.{$k}", "result.{$k}", "data.token.{$k}"] as $path) {
                $v = data_get($json, $path);
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------ Référentiels

    /** Codes payeurs (opérateurs / réseaux de paiement par pays) — cache 24 h. */
    public function payerCodes(?string $country = null): array
    {
        $key = 'digitwace:payers:' . ($country ?: 'all');
        return Cache::remember($key, now()->addHours((int) $this->cfg('payer_cache_hours', 24)), function () use ($country) {
            if ($this->partner()) {
                return $this->partnerServices($country);
            }
            $json = $this->call((string) $this->cfg('payer_codes_method', 'post'), 'payer_codes', $country ? [$this->cfg('fields.country') => $country] : []);
            return (array) (data_get($json, 'data') ?? data_get($json, 'payers') ?? $json);
        });
    }

    /**
     * API Partenaire : services de collecte (payments/services → wp-subscription-key)
     * et de versement (payout/services → payoutSubscriptionId), fusionnés en une
     * liste de « payeurs » {payerCode = id du service, pays, opérateur, payin, payout}.
     */
    public function partnerServices(?string $country = null): array
    {
        $out = [];
        foreach (['payin_services' => 'payin', 'payout_services' => 'payout'] as $endpoint => $flow) {
            try {
                $json = $this->call('get', $endpoint);
            } catch (DigitwaceException $e) {
                if ($flow === 'payin' || ! $out) {
                    throw $e;
                }
                continue; // versement non activé sur le compte : on garde la collecte
            }
            foreach (self::listOf($json) as $svc) {
                if (! is_array($svc)) {
                    continue;
                }
                $id = CoverageService::pick($svc, ['id', '_id', 'serviceId', 'service_id', 'subscriptionId', 'subscriptionKey', 'uuid']);
                if ($id === null || $id === '') {
                    continue;
                }
                $iso = CoverageService::countryOf($svc);
                if ($country && $iso && $iso !== strtoupper($country)) {
                    continue;
                }
                $k = (string) $id;
                $prev = $out[$k] ?? null;
                $out[$k] = ['payerCode' => $k, 'countryCode' => $iso,
                    'payerName' => CoverageService::pick($svc, ['name', 'serviceName', 'label', 'operator']),
                    'operatorCode' => CoverageService::pick($svc, ['operator', 'operatorCode', 'provider', 'network', 'paymentMethod']),
                    'currency' => CoverageService::pick($svc, ['currency', 'currencyCode', 'country.currency']),
                    'type' => 'wallet'] + ($prev ?? []) + ['raw_service' => $svc];
                $out[$k]['payin'] = ($prev['payin'] ?? false) || $flow === 'payin';
                $out[$k]['payout'] = ($prev['payout'] ?? false) || $flow === 'payout';
            }
        }
        return array_values($out);
    }

    /** Liste dans une réponse WacePay : data | data.items | data.data | data.docs | racine. */
    public static function listOf(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        foreach (['data.items', 'data.data', 'data.docs', 'data.services', 'data.transactions', 'data.history', 'data', 'items', 'services', 'results'] as $k) {
            $v = data_get($json, $k);
            if (is_array($v) && array_is_list($v)) {
                return $v;
            }
        }
        return array_is_list($json) ? $json : [];
    }

    /** « MTN (CONGO) », « mtn », « Airtel Money » → MTN / AIRTEL… (code attendu par payments/create). */
    public static function operatorCode(?string $op): ?string
    {
        $op = strtoupper(trim((string) $op));
        if ($op === '') {
            return null;
        }
        foreach (['MTN', 'ORANGE', 'AIRTEL', 'MOOV', 'WAVE', 'FREE', 'VODACOM', 'M-PESA', 'MPESA', 'TIGO', 'EXPRESSO', 'CAMTEL', 'AFRICELL'] as $o) {
            if (str_contains($op, $o)) {
                return $o === 'M-PESA' ? 'MPESA' : $o;
            }
        }
        return trim(preg_replace('/\s*\(.*\)\s*/', '', $op)) ?: null;
    }

    /** Opérateur reconnu dans un libellé de service WacePay (MTN, AIRTEL…), sinon null (service générique). */
    public static function knownOperator(string $label): ?string
    {
        $l = strtoupper($label);
        foreach (['MTN', 'ORANGE', 'AIRTEL', 'MOOV', 'WAVE', 'FREE', 'VODACOM', 'M-PESA', 'MPESA', 'TIGO', 'EXPRESSO', 'CAMTEL', 'AFRICELL'] as $o) {
            if (str_contains($l, $o)) {
                return $o === 'M-PESA' ? 'MPESA' : $o;
            }
        }
        return null;
    }

    /** Opérateur d'une ligne de couverture : nom du service, puis champs bruts operator / operatorCode / provider / network. */
    public static function serviceOperator(object $row): ?string
    {
        if ($op = self::knownOperator((string) ($row->payer_name ?? ''))) {
            return $op;
        }
        $raw = (array) ($row->raw ?? []);
        foreach (['operator', 'operatorCode', 'operator_code', 'provider', 'network', 'paymentMethod'] as $k) {
            $v = $raw[$k] ?? null;
            if (is_array($v)) {
                $v = $v['code'] ?? $v['name'] ?? null;
            }
            if (is_string($v) && ($op = self::knownOperator($v))) {
                return $op;
            }
        }
        return null;
    }

    /** Opérateur (MTN, ORANGE…) d'un service synchronisé, pour payments/create. */
    public function operatorFor(string $serviceId): ?string
    {
        try {
            $row = \App\Models\WacepayCoverage::where('payer_code', $serviceId)->first();
        } catch (\Throwable) {
            $row = null;
        }
        $raw = (array) ($row?->raw ?? []);
        $op = $raw['operator'] ?? $raw['operatorCode'] ?? $raw['operator_code'] ?? $raw['provider'] ?? $raw['network'] ?? null;
        if (is_array($op)) {
            $op = $op['code'] ?? $op['name'] ?? null;
        }
        if (is_string($op) && $op !== '') {
            return strtoupper($op);
        }
        $name = strtoupper((string) ($row?->payer_name ?? ''));
        foreach (['MTN', 'ORANGE', 'AIRTEL', 'MOOV', 'WAVE', 'FREE', 'VODACOM', 'MPESA', 'M-PESA', 'TIGO', 'EXPRESSO', 'CAMTEL', 'AFRICELL'] as $o) {
            if (str_contains($name, $o)) {
                return $o;
            }
        }
        return null;
    }

    /**
     * payerCode à utiliser pour un pays / opérateur. Priorité : correspondance
     * forcée dans la config (DIGITWACE_PAYER_CODES=CG:MTN=XXXX,…), sinon recherche
     * dans la liste WacePay par pays + nom d'opérateur.
     */
    public function payerCodeFor(string $country, ?string $operator, string $service = 'payout'): ?string
    {
        $map = (array) $this->cfg('payer_map', []);
        foreach ([strtoupper($country) . ':' . strtoupper((string) $operator), strtoupper($country)] as $k) {
            if (! empty($map[$k])) {
                return $map[$k];
            }
        }
        // Couverture synchronisée (console › Pays & change › Synchroniser WacePay)
        try {
            $rows = \App\Models\WacepayCoverage::where('country', strtoupper($country))->where($service, true)->get();
            $op = self::operatorCode($operator);
            // Opérateur du service : libellé, sinon champ operator du service WacePay
            // (un service « Mobile Money SN » dont l'opérateur réel est MOOV ne doit pas servir pour un numéro Orange).
            $opOf = fn ($r) => self::serviceOperator($r);
            $pick = $op ? $rows->first(fn ($r) => $opOf($r) === $op) : null;
            if (! $pick && $op && $service === 'payin' && $rows->contains(fn ($r) => $opOf($r) !== null)) {
                // Collecte : un service MTN ne peut pas débiter un numéro Airtel (« Service does not match subscription »)
                return null;
            }
            $pick ??= $rows->first(fn ($r) => $opOf($r) === null && $r->method === 'wallet')
                ?? $rows->first(fn ($r) => $opOf($r) === null)
                ?? ($service === 'payin' && $op ? null : ($rows->firstWhere('method', 'wallet') ?? $rows->first()));
            if ($pick) {
                return $pick->payer_code;
            }
        } catch (\Throwable) {
            // table absente : recherche directe ci-dessous
        }
        foreach ($this->payerCodes($country) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pc = strtoupper((string) ($p['country'] ?? $p['countryCode'] ?? $p['country_code'] ?? $country));
            $name = strtoupper((string) ($p['name'] ?? $p['payerName'] ?? $p['label'] ?? ''));
            $code = $p['payerCode'] ?? $p['code'] ?? $p['payer_code'] ?? null;
            if ($code && $pc === strtoupper($country) && (! $operator || str_contains($name, (string) (self::operatorCode($operator) ?? strtoupper($operator))))) {
                return (string) $code;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ Parties (mémorisées)

    /** @param array{name:string, phone:string, country:string} $p */
    public function senderCode(array $p): string
    {
        return $this->partyCode('sender', $p);
    }

    /** @param array{name:string, phone:string, country:string} $p */
    public function beneficiaryCode(array $p): string
    {
        return $this->partyCode('beneficiary', $p);
    }

    protected function partyCode(string $kind, array $p): string
    {
        $key = strtoupper($p['country']) . ':' . preg_replace('/\D/', '', $p['phone']) . ':' . mb_strtoupper(trim($p['name']));
        $key = mb_substr($key, 0, 120);
        if ($known = DigitwaceParty::where('kind', $kind)->where('key', $key)->value('code')) {
            return $known;
        }

        [$first, $last] = $this->splitName($p['name']);
        $f = $this->cfg('fields.party');
        $json = $this->call('post', $kind === 'sender' ? 'create_sender' : 'create_beneficiary', array_filter([
            $f['first_name'] => $first,
            $f['last_name'] => $last,
            $f['phone'] => preg_replace('/\D/', '', $p['phone']),
            $f['country'] => strtoupper($p['country']),
            $f['address'] => $p['address'] ?? $this->cfg('default_address'),
            $f['city'] => $p['city'] ?? $this->cfg('default_city'),
            'pep' => false,
            'updateIfExist' => true,
        ], fn ($v) => $v !== null && $v !== ''));

        $code = data_get($json, $kind . 'Code') ?? data_get($json, 'data.' . $kind . 'Code') ?? data_get($json, 'code') ?? data_get($json, 'data.code');
        if (! $code) {
            throw new DigitwaceException('WacePay : ' . ($kind === 'sender' ? 'expéditeur' : 'bénéficiaire') . ' non créé (' . $this->errorText($json) . ')', $this->codeOf($json));
        }
        DigitwaceParty::updateOrCreate(['kind' => $kind, 'key' => $key], ['code' => (string) $code]);

        return (string) $code;
    }

    // ------------------------------------------------------------ Transactions

    /**
     * Versement vers un wallet mobile money : Wallet puis Confirm, dans la file unique.
     *
     * @return array{status:string, wace_id:?string, raw:array}
     */
    public function payout(string $reference, string $senderCode, string $beneficiaryCode, string $payerCode, int $amount, string $currency, string $phone, ?string $purpose = null, ?string $payoutCountry = null, ?string $fromCountry = null, ?string $sendingCurrency = null): array
    {
        $f = $this->cfg('fields.transaction');

        return Cache::lock('digitwace:queue', 60)->block((int) $this->cfg('queue_wait_seconds', 45), function () use ($f, $reference, $senderCode, $beneficiaryCode, $payerCode, $amount, $currency, $phone, $purpose, $payoutCountry, $fromCountry, $sendingCurrency) {
            // Champs de transaction/wallet/create (doc WacePay Business)
            $created = $this->call('post', 'wallet', array_filter([
                $f['reference'] => $reference,
                'senderCode' => $senderCode,
                'beneficiaryCode' => $beneficiaryCode,
                'payerCode' => is_numeric($payerCode) ? (int) $payerCode : $payerCode,
                'payoutCountry' => $payoutCountry ? strtoupper($payoutCountry) : null,
                'payoutCity' => $this->cfg('default_city'),
                'receiveCurrency' => $currency,
                'sendingCurrency' => $sendingCurrency ?: $currency,
                'amountToPaid' => $amount,
                'service' => (string) $this->cfg('payout_service', 'WALLET'),
                'mobileReceiveNumber' => preg_replace('/\D/', '', $phone),
                'fromCountry' => strtoupper($fromCountry ?: (string) config('flashpay.peex.sender_country', 'CG')),
                'originFund' => (string) $this->cfg('origin_fund', 'SALARY'),
                'reason' => $purpose ?: (string) $this->cfg('default_purpose', 'FAMILY SUPPORT'),
                'relation' => (string) $this->cfg('relation', 'FRIEND'),
                $f['callback_url'] => $this->callbackUrl(),
            ], fn ($v) => $v !== null && $v !== ''));

            $waceId = (string) (data_get($created, 'data.transactionCode') ?? data_get($created, 'transactionCode')
                ?? data_get($created, 'data.code') ?? data_get($created, 'data.id') ?? data_get($created, 'id') ?? $reference);

            $confirmed = $this->call('post', 'confirm', [$f['transaction_code'] => $waceId, $f['reference'] => $reference]);

            return [
                'status' => self::normalize(data_get($confirmed, 'data.status') ?? data_get($confirmed, 'status') ?? 'pending'),
                'wace_id' => $waceId,
                'raw' => ['wallet' => $created, 'confirm' => $confirmed],
            ];
        });
    }

    /**
     * API Partenaire : versement mobile money (POST payout/execute) dans la file unique.
     *
     * @return array{status:string, wace_id:?string, raw:array}
     */
    public function payoutDirect(string $reference, string $subscriptionId, int $amount, string $phone, ?string $name = null): array
    {
        return Cache::lock('digitwace:queue', 60)->block((int) $this->cfg('queue_wait_seconds', 45), function () use ($reference, $subscriptionId, $amount, $phone, $name) {
            $json = $this->call('post', 'payout', array_filter([
                'amount' => $amount,
                'recipientMsisdn' => '+' . preg_replace('/\D/', '', $phone),
                'recipientName' => $name ? mb_substr($name, 0, 100) : null,
                'payoutSubscriptionId' => $subscriptionId,
                'callbackUrl' => $this->callbackUrl(),
            ], fn ($v) => $v !== null && $v !== ''));

            $id = data_get($json, 'data.id') ?? data_get($json, 'data.transactionId') ?? data_get($json, 'data._id') ?? data_get($json, 'id') ?? data_get($json, 'transactionId');

            return [
                'status' => self::normalize(data_get($json, 'data.status') ?? 'pending'),
                'wace_id' => $id !== null ? (string) $id : null,
                'raw' => $json,
            ];
        });
    }

    /**
     * Collecte (PAYIN) : demande de paiement envoyée au wallet mobile money du
     * client, qui valide avec son code secret. Asynchrone : statut par webhook / status().
     *
     * @return array{status:string, wace_id:?string, raw:array}
     */
    public function payin(string $reference, string $payerCode, int $amount, string $currency, string $phone, string $name, ?string $country = null, ?string $operator = null, ?string $email = null): array
    {
        if ($this->partner()) {
            // POST payments/create — wp-subscription-key = id du service de collecte.
            // WacePay (réponse réelle) : customer_msisdn = TEXTE de 8 à 14 chiffres, « + » facultatif.
            $digits = preg_replace('/\D/', '', $phone);
            $body = array_filter([
                'amount' => $amount,
                'referenceId' => $reference,
                'customer_msisdn' => '+' . $digits,
                'customer_name' => mb_substr(trim($name) ?: 'Client FlashPay', 0, 100),
                'customer_email' => $email ?: $this->cfg('default_email'),
                'currency' => strtoupper($currency),
                'countryCode' => $country ? strtoupper($country) : null,
                'operator' => self::operatorCode($operator) ?? self::operatorCode($this->operatorFor($payerCode)),
                'callback_url' => $this->callbackUrl(),
            ], fn ($v) => $v !== null && $v !== '');
            try {
                $json = $this->call('post', 'payin', $body, [], ['wp-subscription-key' => $payerCode]);
            } catch (DigitwaceException $e) {
                // Format du numéro refusé (400/422) : 2e essai sans « + » (texte, chiffres seuls)
                if (! in_array($e->httpStatus, [400, 422], true) || ! str_contains(strtolower($e->getMessage() . json_encode($e->response ?? [])), 'msisdn')) {
                    throw $e;
                }
                $json = $this->call('post', 'payin', ['customer_msisdn' => (string) $digits] + $body, [], ['wp-subscription-key' => $payerCode]);
            }

            return [
                'status' => self::normalize(data_get($json, 'data.status') ?? 'pending'),
                'wace_id' => (string) (data_get($json, 'data.referenceId') ?? $reference),
                'raw' => $json,
            ];
        }
        $f = $this->cfg('fields.transaction');
        $json = $this->call('post', 'payin', array_filter([
            $f['reference'] => $reference,
            $f['payer_code'] => $payerCode,
            $f['amount'] => $amount,
            $f['currency'] => $currency,
            $f['wallet_number'] => preg_replace('/\D/', '', $phone),
            'customerName' => $name,
            $this->cfg('fields.country') => $country,
            $f['callback_url'] => $this->callbackUrl(),
        ], fn ($v) => $v !== null && $v !== ''));

        return [
            'status' => self::normalize(data_get($json, 'data.status') ?? data_get($json, 'status') ?? 'pending'),
            'wace_id' => (string) (data_get($json, 'data.transactionCode') ?? data_get($json, 'transactionCode') ?? data_get($json, 'data.id') ?? $reference),
            'raw' => $json,
        ];
    }

    /**
     * Collecte par carte Visa / Mastercard ou par compte bancaire : WacePay crée une
     * page de paiement sécurisée (3-D Secure / banque) et renvoie son URL. Le client
     * y saisit sa carte ou valide le prélèvement ; le résultat arrive par webhook
     * (revérifié via status()). FlashPay ne voit jamais le numéro de carte.
     *
     * @param  'card'|'bank'  $method
     * @return array{url:?string, wace_id:string, status:string, raw:array}
     */
    public function checkout(string $method, string $reference, int $amount, string $currency, array $customer, string $returnUrl, ?string $serviceId = null): array
    {
        if ($this->partner()) {
            return $this->partnerCheckout($method, $reference, $amount, $currency, $customer, $returnUrl, $serviceId);
        }
        $f = $this->cfg('fields.transaction');
        [$first, $last] = $this->splitName($customer['name'] ?? 'Client FlashPay');
        $json = $this->call('post', $method === 'bank' ? 'payin_bank' : 'payin_card', array_filter([
            $f['reference'] => $reference,
            $f['amount'] => $amount,
            $f['currency'] => $currency,
            'paymentMethod' => $method === 'bank' ? 'BANK' : 'CARD',
            'description' => $customer['description'] ?? "FlashPay {$reference}",
            'firstName' => $first,
            'lastName' => $last,
            'customerName' => $customer['name'] ?? null,
            'customerEmail' => $customer['email'] ?? null,
            'customerPhone' => isset($customer['phone']) ? '+' . preg_replace('/\D/', '', $customer['phone']) : null,
            $this->cfg('fields.country') => $customer['country'] ?? null,
            $f['callback_url'] => $this->callbackUrl(),
            'returnUrl' => $returnUrl,
            'successUrl' => $returnUrl,
            'cancelUrl' => $returnUrl,
            'failureUrl' => $returnUrl,
        ], fn ($v) => $v !== null && $v !== ''));

        $url = collect(['paymentUrl', 'payment_url', 'checkoutUrl', 'checkout_url', 'redirectUrl', 'redirect_url', 'paymentLink', 'payment_link', 'link', 'url'])
            ->flatMap(fn ($k) => ["data.{$k}", $k, "data.payment.{$k}"])
            ->map(fn ($k) => data_get($json, $k))
            ->first(fn ($v) => is_string($v) && str_starts_with($v, 'http'));

        return [
            'url' => $url,
            'wace_id' => (string) (data_get($json, 'data.transactionCode') ?? data_get($json, 'transactionCode') ?? data_get($json, 'data.id') ?? $reference),
            'status' => self::normalize(data_get($json, 'data.status') ?? data_get($json, 'status') ?? 'pending'),
            'raw' => $json,
        ];
    }

    /**
     * API Partenaire : carte / compte bancaire = un service de collecte WacePay dédié
     * (wp-subscription-key) ; payments/create renvoie l'adresse de la page de paiement.
     */
    protected function partnerCheckout(string $method, string $reference, int $amount, string $currency, array $customer, string $returnUrl, ?string $serviceId): array
    {
        $serviceId ??= $this->checkoutServiceFor($method, $customer['country'] ?? null);
        if (! $serviceId) {
            throw new DigitwaceException('Aucun service WacePay ' . ($method === 'bank' ? '« compte bancaire »' : '« carte »')
                . ' trouvé : synchronisez la couverture WacePay ou renseignez DIGITWACE_' . ($method === 'bank' ? 'BANK' : 'CARD') . '_SERVICE_ID.', '1001');
        }
        $phone = isset($customer['phone']) ? preg_replace('/\D/', '', (string) $customer['phone']) : null;
        $json = $this->call('post', 'payin', array_filter([
            'amount' => $amount,
            'referenceId' => $reference,
            'currency' => strtoupper($currency),
            'customer_name' => mb_substr(trim((string) ($customer['name'] ?? '')) ?: 'Client FlashPay', 0, 100),
            'customer_email' => ($customer['email'] ?? null) ?: $this->cfg('default_email'),
            'customer_msisdn' => $phone ? '+' . preg_replace('/\D/', '', (string) $phone) : null,
            'countryCode' => isset($customer['country']) ? strtoupper($customer['country']) : null,
            'payment_method' => $method === 'bank' ? 'BANK' : 'CARD',
            'description' => $customer['description'] ?? "FlashPay {$reference}",
            'callback_url' => $this->callbackUrl(),
            'return_url' => $returnUrl,
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
        ], fn ($v) => $v !== null && $v !== ''), [], ['wp-subscription-key' => $serviceId]);

        $url = collect(['paymentUrl', 'payment_url', 'checkoutUrl', 'checkout_url', 'redirectUrl', 'redirect_url', 'paymentLink', 'payment_link', 'link', 'url'])
            ->flatMap(fn ($k) => ["data.{$k}", $k, "data.payment.{$k}"])
            ->map(fn ($k) => data_get($json, $k))
            ->first(fn ($v) => is_string($v) && str_starts_with($v, 'http'));

        return [
            'url' => $url,
            'wace_id' => (string) (data_get($json, 'data.referenceId') ?? $reference),
            'status' => self::normalize(data_get($json, 'data.status') ?? 'pending'),
            'raw' => $json,
        ];
    }

    /** Type d'un service WacePay d'après son nom / ses champs : card | bank | wallet. */
    public static function serviceType(array $svc): string
    {
        $txt = strtoupper(json_encode($svc, JSON_UNESCAPED_UNICODE) ?: '');
        return match (true) {
            (bool) preg_match('/VISA|MASTERCARD|CARTE|\bCARD\b|CARD_|CREDIT ?CARD|\bCB\b/', $txt) => 'card',
            (bool) preg_match('/BANK|BANQUE|VIREMENT|TRANSFER_BANK|\bRIB\b|\bIBAN\b/', $txt) => 'bank',
            default => 'wallet',
        };
    }

    /** Service de collecte carte / banque : variable DIGITWACE_CARD_SERVICE_ID / _BANK_, sinon couverture synchronisée. */
    public function checkoutServiceFor(string $method, ?string $country = null): ?string
    {
        if ($id = $this->cfg($method === 'bank' ? 'bank_service_id' : 'card_service_id')) {
            return (string) $id;
        }
        try {
            $rows = \App\Models\WacepayCoverage::where('payin', true)->get()
                ->filter(fn ($r) => self::serviceType(array_merge((array) ($r->raw ?? []), ['name' => $r->payer_name, 'method' => $r->method])) === $method);
            $pick = ($country ? $rows->firstWhere('country', strtoupper($country)) : null) ?? $rows->first();
            return $pick?->payer_code;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{status:string, raw_status:?string, message:?string, raw:array} */
    public function status(string $waceIdOrReference, ?string $operation = null, ?string $reference = null): array
    {
        if ($this->partner()) {
            if (in_array($operation, ['payin', 'checkout'], true)) {
                $json = $this->call('get', 'payin_status', [], [], ['referenceId' => $waceIdOrReference]);
            } else {
                $json = $this->payoutStatusJson($waceIdOrReference, $reference);
            }
            // Le statut de l'opération peut être à plusieurs endroits ; « status: true » en tête
            // de réponse = succès de l'APPEL, pas de la transaction (ignoré).
            $raw = self::findStatus($json);

            return [
                'status' => self::normalize($raw),
                'raw_status' => is_scalar($raw) ? (string) $raw : null,
                'message' => data_get($json, 'data.message') ?? data_get($json, 'data.failureReason') ?? data_get($json, 'message'),
                'raw' => $json,
            ];
        }
        $json = str_contains((string) $this->cfg('paths.status'), '{ref}')
            ? $this->call('get', 'status', [], ['ref' => rawurlencode($waceIdOrReference)])
            : $this->call('get', 'status', [$this->cfg('fields.transaction.transaction_code') => $waceIdOrReference]);
        $raw = data_get($json, 'data.status') ?? data_get($json, 'status') ?? data_get($json, 'data.0.status');

        return [
            'status' => self::normalize($raw),
            'raw_status' => is_scalar($raw) ? (string) $raw : null,
            'message' => data_get($json, 'data.message') ?? data_get($json, 'message'),
            'raw' => $json,
        ];
    }

    /** Solde du compte WacePay (si l'API l'expose). */
    public function balance(): ?array
    {
        try {
            return $this->call('get', 'balance');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Versement : refresh-status/{id} → transactions/{id} → recherche dans la liste des
     * versements (par id WacePay, code transaction ou notre référence). Lève une erreur
     * détaillée si rien ne répond (le motif est gardé sur la demande).
     */
    protected function payoutStatusJson(string $id, ?string $reference): array
    {
        $errors = [];
        $first = null;
        foreach (array_unique(array_filter([$id, $reference])) as $key) {
            foreach (['payout_refresh', 'payout_tx'] as $ep) {
                try {
                    $j = $this->call('get', $ep, [], ['id' => rawurlencode($key)]);
                    if (self::findStatus($j)) {
                        return $j;
                    }
                    $first ??= $j;
                } catch (DigitwaceException $e) {
                    $errors[] = "{$ep}/{$key} : " . mb_substr($e->getMessage(), 0, 120);
                }
            }
        }
        try {
            $list = $this->call('get', 'payout_list', ['search' => $reference ?: $id, 'limit' => 50]);
            $items = collect([data_get($list, 'data.data'), data_get($list, 'data.items'), data_get($list, 'data.transactions'), data_get($list, 'data'), data_get($list, 'items')])
                ->first(fn ($v) => is_array($v) && array_is_list($v)) ?? [];
            $needles = array_filter([$id, $reference]);
            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $vals = array_map('strval', array_filter(\Illuminate\Support\Arr::flatten($it), 'is_scalar'));
                if (array_intersect($needles, $vals)) {
                    return ['data' => $it, 'source' => 'payout_list'];
                }
            }
            $errors[] = 'payout_list : versement introuvable (' . implode(', ', $needles) . ')';
        } catch (DigitwaceException $e) {
            $errors[] = 'payout_list : ' . mb_substr($e->getMessage(), 0, 120);
        }
        if ($first) {
            return $first;
        }
        throw new DigitwaceException('Statut WacePay introuvable — ' . implode(' | ', $errors));
    }

    /** Statut de transaction dans une réponse WacePay (premier libellé texte trouvé). */
    public static function findStatus(array $json): ?string
    {
        foreach (['data.status', 'data.transaction.status', 'data.payment.status', 'data.paymentStatus', 'data.transactionStatus',
            'data.0.status', 'data.data.status', 'data.payout.status', 'transaction.status', 'paymentStatus', 'transactionStatus'] as $k) {
            $v = data_get($json, $k);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        // Recherche en profondeur : clé « status » / « paymentStatus » / « transactionStatus » texte
        $walk = function (array $a, int $d) use (&$walk): ?string {
            foreach ($a as $k => $v) {
                if (is_string($k) && in_array(strtolower($k), ['status', 'paymentstatus', 'transactionstatus', 'state'], true) && is_string($v) && $v !== '') {
                    return $v;
                }
            }
            foreach ($a as $v) {
                if (is_array($v) && $d < 4 && ($r = $walk($v, $d + 1)) !== null) {
                    return $r;
                }
            }
            return null;
        };
        // Dans « data » seulement : le « status » de premier niveau peut décrire l'APPEL (« success »), pas la transaction
        if (is_array($json['data'] ?? null)) {
            return $walk($json['data'], 0);
        }
        $top = $json['status'] ?? null;
        return is_string($top) && $top !== '' ? $top : null;
    }

    public static function normalize(mixed $s): string
    {
        $s = strtolower(trim((string) $s));
        return match (true) {
            in_array($s, ['success', 'successful', 'successfull', 'succeeded', 'succes', 'paid', 'completed', 'complete', 'done', 'approved', 'delivered', 'confirmed_paid', 'settled', 'credited', 'validated'], true) => 'successful',
            in_array($s, ['failed', 'failure', 'rejected', 'declined', 'cancel', 'canceled', 'cancelled', 'error', 'refunded', 'expired', 'reversed'], true) => 'failed',
            default => 'pending',
        };
    }

    // ------------------------------------------------------------ HTTP

    /** Appel authentifié ; un 401 renouvelle le jeton une fois. */
    public function call(string $method, string $endpoint, array $data = [], array $params = [], array $headers = []): array
    {
        $path = $this->path($endpoint);
        foreach ($params as $k => $v) {
            $path = str_replace('{' . $k . '}', (string) $v, $path);
        }
        $do = fn (string $token) => $method === 'get'
            ? $this->http()->withToken($token)->withHeaders($headers)->get($path, $data)
            : $this->http()->withToken($token)->withHeaders($headers)->post($path, $data);

        $res = $do($this->token());
        if ($res->status() === 401) {
            $res = $do($this->token(fresh: true));
        }
        $json = $res->json() ?? [];
        $code = $this->codeOf($json);

        $failed = ! $res->successful() || ($code !== null && $code !== self::OK);
        Log::log($failed ? 'warning' : 'info', 'WacePay ' . strtoupper($method) . ' ' . $endpoint, array_filter([
            'http' => $res->status(), 'code' => $code, 'ref' => $data['referenceId'] ?? $headers['referenceId'] ?? $headers['X-Reference-Id'] ?? $data[$this->cfg('fields.transaction.reference')] ?? null,
            // En cas d'échec : réponse complète de WacePay + champs envoyés (sans valeurs sensibles)
            'body' => $failed ? mb_substr((string) $res->body(), 0, 1500) : null,
            'sent' => $failed && $method !== 'get' ? self::redact($data) : null,
        ], fn ($v) => $v !== null));

        if ($failed) {
            throw new DigitwaceException('WacePay : ' . $this->errorText($json, $res->status()), $code, $res->status(), $json);
        }

        return $json;
    }

    /** Champs envoyés, numéros masqués (journaux). */
    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_scalar($v) && preg_match('/msisdn|phone|wallet|number|email/i', (string) $k)) {
                $v = (string) $v;
                $data[$k] = mb_strlen($v) > 6 ? mb_substr($v, 0, 5) . str_repeat('•', mb_strlen($v) - 7) . mb_substr($v, -2) : '•••';
            }
        }
        return $data;
    }

    protected function http()
    {
        return Http::baseUrl(rtrim((string) $this->cfg('base_url'), '/') . '/')
            ->acceptJson()
            ->asJson()
            ->timeout((int) $this->cfg('timeout', 30))
            ->withHeaders(array_filter(['X-API-KEY' => $this->cfg('send_api_key_header') ? $this->cfg('public_key') : null]));
    }

    /**
     * Diagnostic de connexion (console) : appelle la connexion WacePay et renvoie
     * ce que le serveur répond réellement (code HTTP, en-têtes, extrait du corps),
     * sans jamais exposer les clés.
     */
    public function diagnose(): array
    {
        $base = rtrim((string) $this->cfg('base_url'), '/') . '/';
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $pub = (string) $this->cfg('public_key');
        $priv = (string) $this->cfg('private_key');
        $out = [
            'base_url' => $base,
            'login_url' => $base . $this->path('login'),
            'host_ip' => $host ? (gethostbyname($host) ?: null) : null,
            'sandbox' => (bool) $this->cfg('sandbox', true),
            'enabled' => (bool) $this->cfg('enabled'),
            'public_key' => $pub ? substr($pub, 0, 6) . '…' . substr($pub, -4) . ' (' . strlen($pub) . ' car.)' : null,
            'private_key' => $priv ? 'présente (' . strlen($priv) . ' car.)' : null,
            'api' => $this->partner() ? 'partner' : 'legacy',
            'auth_mode' => $this->partner() ? 'basic' : (string) $this->cfg('auth_mode', 'basic'),
            'login_fields' => ($this->partner() || $this->cfg('auth_mode', 'basic') === 'basic') ? ['Authorization: Basic base64(public:private)'] : array_values($this->cfg('fields.login')),
            'api_key_header' => (bool) $this->cfg('send_api_key_header'),
            'override' => self::endpointOverride(),
        ];
        $f = $this->cfg('fields.login');
        $t = microtime(true);
        try {
            $res = $this->loginRequest();
            $body = (string) $res->body();
            foreach (array_filter([$pub, $priv]) as $secret) {
                $body = str_replace($secret, '***', $body);
            }
            $json = $res->json();
            $token = self::tokenFrom($json);
            $out += [
                'http' => $res->status(),
                'ms' => (int) round((microtime(true) - $t) * 1000),
                'server' => $res->header('Server') ?: null,
                'content_type' => $res->header('Content-Type') ?: null,
                'cf_ray' => $res->header('CF-Ray') ?: null,
                'body' => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 400),
                'token_ok' => (bool) $token,
                'message' => is_array($json) ? $this->errorText($json, $res->status()) : $this->errorText([], $res->status()),
            ];
            // API Partenaire : on vérifie aussi l'accès aux services (IP autorisée, abonnement actif)
            if ($token && $this->partner()) {
                Cache::put('digitwace:token:' . md5($pub), $token, now()->addMinutes((int) $this->cfg('token_ttl_minutes', 55)));
                foreach (['payin_services' => 'services_payin', 'payout_services' => 'services_payout'] as $ep => $k) {
                    try {
                        $out[$k] = count(self::listOf($this->call('get', $ep)));
                    } catch (\Throwable $e) {
                        $out[$k] = null;
                        $out[$k . '_error'] = mb_substr($e->getMessage(), 0, 200);
                    }
                }
            }
        } catch (\Throwable $e) {
            $out += ['http' => null, 'ms' => (int) round((microtime(true) - $t) * 1000), 'token_ok' => false,
                'message' => 'Connexion impossible : ' . mb_substr($e->getMessage(), 0, 300)];
        }
        return $out;
    }

    protected function path(string $endpoint): string
    {
        if ($this->partner()) {
            $custom = (array) $this->cfg('partner_paths', []);
            if (! empty($custom[$endpoint]) || isset(self::PARTNER_PATHS[$endpoint])) {
                return ltrim((string) ($custom[$endpoint] ?? self::PARTNER_PATHS[$endpoint]), '/');
            }
        }
        return ltrim((string) $this->cfg('paths.' . $endpoint), '/');
    }

    public function callbackUrl(): string
    {
        return $this->cfg('callback_url') ?: rtrim((string) config('app.url'), '/') . '/api/webhooks/digitwace';
    }

    protected function codeOf(array $json): ?string
    {
        $c = data_get($json, 'code') ?? data_get($json, 'statusCode') ?? data_get($json, 'status_code');
        return is_scalar($c) && preg_match('/^\d{4}$/', (string) $c) ? (string) $c : null;
    }

    protected function errorText(array $json, ?int $http = null): string
    {
        $code = $this->codeOf($json);
        $msg = data_get($json, 'message') ?? data_get($json, 'error') ?? data_get($json, 'errors');
        $msg = is_array($msg) ? json_encode($msg, JSON_UNESCAPED_UNICODE) : (string) $msg;
        $msg = mb_substr(strip_tags($msg), 0, 200);
        $hint = match (true) {
            str_contains(strtolower($msg), 'whitelist') => 'IP du serveur pas encore autorisée chez WacePay : vérifiez Developers › IP Whitelist et que DIGITWACE_BASE_URL pointe bien sur la même plateforme (sandbox-payinws.wacepay.io pour la sandbox)',
            $code === '4001' => 'accès refusé — vérifiez que l\'IP du serveur est autorisée (IP Whitelist)',
            $code === '3002' => 'compte ou solde WacePay inactif',
            in_array($code, ['3015', '3016'], true) => 'référence invalide ou déjà utilisée',
            in_array($code, ['2001', '3001'], true) => 'erreur système WacePay',
            $code !== null && $code >= '1001' && $code <= '1017' => 'données refusées',
            $http === 401 => 'clés API refusées : vérifiez DIGITWACE_PUBLIC_KEY / DIGITWACE_PRIVATE_KEY (sandbox ou production selon le compte)',
            $http === 403 => 'IP du serveur non autorisée : ajoutez l\'IP sortante du serveur dans WacePay › Developers › IP Whitelist (statut « Active », pas « Blocked »)',
            $http === 404 => 'adresse de l\'API introuvable : vérifiez DIGITWACE_BASE_URL (URL sandbox ou production) et les chemins DIGITWACE_PATH_*',
            in_array($http, [502, 503, 504], true) => 'serveur WacePay injoignable ou URL incorrecte : vérifiez DIGITWACE_BASE_URL (URL sandbox fournie par WacePay) ; si l\'URL est bonne, WacePay bloque l\'IP du serveur ou est en panne',
            $http !== null && $http >= 500 => 'erreur côté WacePay, réessayez plus tard',
            default => null,
        };
        return trim(($code ? "[{$code}] " : '') . ($msg ?: ($http ? "HTTP {$http}" : 'erreur')) . ($hint ? " ({$hint})" : ''));
    }

    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: ['Client'];
        $first = array_shift($parts) ?: 'Client';
        return [$first, implode(' ', $parts) ?: $first];
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        // Adresse / connexion détectées automatiquement depuis la console (prioritaires sur .env)
        $o = self::endpointOverride();
        $map = ['base_url' => 'base_url', 'paths.login' => 'login_path', 'fields.login' => 'login_fields', 'send_api_key_header' => 'api_key_header', 'auth_mode' => 'auth_mode'];
        if ($o && in_array($key, ['paths.login', 'fields.login', 'auth_mode', 'send_api_key_header'], true) && config('flashpay.digitwace.api', 'partner') !== 'legacy') {
            $o = null; // API Partenaire : connexion fixe (GET payments/get-token + Basic)
        }
        if ($o && isset($map[$key]) && array_key_exists($map[$key], $o)) {
            return $o[$map[$key]];
        }
        return config('flashpay.digitwace.' . $key, $default);
    }

    public static function endpointOverride(): ?array
    {
        try {
            $v = app(\App\Services\Ops\PlatformSettings::class)->get('digitwace_endpoint');
            return is_array($v) && ! empty($v['base_url']) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Détection automatique de l'adresse de connexion WacePay (doc indisponible) :
     * essaie les adresses et chemins plausibles, uniquement sur des domaines
     * *.wacepay.com en HTTPS, et retient la première combinaison qui renvoie un jeton.
     * Les clés ne sont jamais renvoyées.
     */
    public function discover(bool $save = true): array
    {
        @set_time_limit(130);
        $started = microtime(true);
        $pub = (string) config('flashpay.digitwace.public_key');
        $priv = (string) config('flashpay.digitwace.private_key');
        if (! $pub || ! $priv) {
            return ['found' => null, 'attempts' => [], 'message' => 'Clés WacePay absentes (DIGITWACE_PUBLIC_KEY / DIGITWACE_PRIVATE_KEY).'];
        }

        $bases = array_values(array_unique(array_filter(array_map(fn ($b) => rtrim(trim($b), '/') . '/', array_merge(
            [(string) config('flashpay.digitwace.base_url')],
            explode(',', (string) config('flashpay.digitwace.discover_bases', '')),
            [
                'https://sandbox-payinws.wacepay.io/api/v1/', 'https://payinws.wacepay.io/api/v1/',
                'https://sandbox.wacepay.com/api/v1/', 'https://api.wacepay.com/api/v1/',
                'https://payinws.wacepay.com/api/v1/', 'https://payinws.wacepay.com/api/',
                'https://api.wacepay.com/api/v1/', 'https://api.wacepay.com/api/', 'https://api.wacepay.com/v1/', 'https://api.wacepay.com/',
                'https://sandbox.wacepay.com/api/v1/', 'https://sandbox-api.wacepay.com/api/v1/', 'https://api-sandbox.wacepay.com/api/v1/',
                'https://api.sandbox.wacepay.com/api/v1/', 'https://test.wacepay.com/api/v1/', 'https://dev.wacepay.com/api/v1/',
                'https://app.wacepay.com/api/v1/', 'https://dashboard.wacepay.com/api/v1/', 'https://wacepay.com/api/v1/', 'https://www.wacepay.com/api/v1/',
            ]
        )), function ($b) {
            $h = strtolower((string) parse_url($b, PHP_URL_HOST));
            return str_starts_with($b, 'https://') && (in_array($h, ['wacepay.com', 'wacepay.io'], true) || str_ends_with($h, '.wacepay.com') || str_ends_with($h, '.wacepay.io'));
        })));
        $paths = array_values(array_unique(array_filter([
            (string) config('flashpay.digitwace.paths.login'),
            'auth/login', 'login', 'auth/token', 'token', 'oauth/token', 'auth', 'authenticate', 'auth/authenticate',
            'merchant/login', 'partner/login', 'api-key/login', 'developer/login', 'access-token', 'auth/access-token',
        ])));
        $fieldSets = [
            ['apiKey', 'secretKey'], ['publicKey', 'privateKey'], ['public_key', 'private_key'], ['api_key', 'secret_key'],
            ['apiKey', 'privateKey'], ['key', 'secret'], ['client_id', 'client_secret'],
        ];

        $attempts = [];
        $found = null;
        $hint = null;
        foreach ($bases as $base) {
            $host = (string) parse_url($base, PHP_URL_HOST);
            if (config('flashpay.digitwace.discover_dns_check', true) && gethostbyname($host) === $host) {
                $attempts[] = ['url' => $base, 'http' => null, 'note' => 'domaine inexistant'];
                continue;
            }
            // Méthode documentée (docs.digitwace.com) : GET get-token + Authorization Basic
            $rp = $this->probeBasic($base, 'payments/get-token', $pub, $priv);
            $attempts[] = $rp;
            if ($rp['token_ok']) {
                $found = ['base_url' => $base, 'login_path' => 'payments/get-token', 'auth_mode' => 'basic'];
                break;
            }
            if ($rp['http'] === null) {
                continue; // hôte injoignable
            }
            $rb = $this->probeBasic($base, 'get-token', $pub, $priv);
            $attempts[] = $rb;
            if ($rb['token_ok']) {
                $found = ['base_url' => $base, 'login_path' => 'get-token', 'auth_mode' => 'basic'];
                break;
            }
            if ($rb['http'] === null) {
                continue; // hôte injoignable
            }
            foreach ($paths as $path) {
                if (microtime(true) - $started > 100) {
                    break 2;
                }
                $r = $this->probe($base, $path, $fieldSets[0], $pub, $priv);
                $attempts[] = $r;
                if ($r['token_ok']) {
                    $found = ['base_url' => $base, 'login_path' => $path, 'auth_mode' => 'login', 'login_fields' => ['public_key' => $fieldSets[0][0], 'private_key' => $fieldSets[0][1]], 'api_key_header' => false];
                    break 2;
                }
                if ($r['http'] === null) {
                    break; // hôte injoignable : on passe à l'adresse suivante
                }
                // Le chemin existe (refus des données / des clés) : on essaie les autres noms de champs
                if (in_array($r['http'], [400, 401, 403, 422], true) && $r['json']) {
                    $hint ??= $r;
                    foreach (array_slice($fieldSets, 1) as $fs) {
                        foreach ([false, true] as $header) {
                            $r2 = $this->probe($base, $path, $fs, $pub, $priv, $header);
                            $attempts[] = $r2;
                            if ($r2['token_ok']) {
                                $found = ['base_url' => $base, 'login_path' => $path, 'auth_mode' => 'login', 'login_fields' => ['public_key' => $fs[0], 'private_key' => $fs[1]], 'api_key_header' => $header];
                                break 4;
                            }
                        }
                    }
                }
            }
        }

        if ($found && $save) {
            app(\App\Services\Ops\PlatformSettings::class)->set('digitwace_endpoint', $found + ['found_at' => now()->toIso8601String()]);
            Cache::forget('digitwace:token:' . md5($pub));
            Log::info('WacePay : adresse de connexion détectée', $found);
        }

        return [
            'found' => $found,
            'hint' => $hint ? ['url' => $hint['url'], 'http' => $hint['http'], 'body' => $hint['body']] : null,
            'attempts' => array_map(fn ($a) => array_diff_key($a, ['json' => 1]), $attempts),
            'seconds' => (int) round(microtime(true) - $started),
            'message' => $found
                ? 'Connexion WacePay trouvée : ' . $found['base_url'] . $found['login_path'] . ' — enregistrée et utilisée immédiatement.'
                : ($hint
                    ? 'Le chemin ' . $hint['url'] . ' existe (HTTP ' . $hint['http'] . ') mais refuse les clés : vérifiez les clés (sandbox / production) ou demandez à WacePay le format exact de connexion.'
                    : 'Aucune adresse WacePay n\'a répondu correctement : WacePay doit vous fournir l\'adresse exacte de son API (et débloquer l\'IP du serveur).'),
        ];
    }

    protected function probeBasic(string $base, string $path, string $pub, string $priv): array
    {
        $url = $base . ltrim($path, '/');
        try {
            $res = Http::acceptJson()->connectTimeout(4)->timeout(8)
                ->withHeaders(['Authorization' => 'Basic ' . base64_encode($pub . ':' . $priv)])->get($url);
            $json = $res->json();
            $body = str_replace([$pub, $priv, base64_encode($pub . ':' . $priv)], '***', mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $res->body()))), 0, 160));
            $tok = self::tokenFrom($json);
            if ($tok) {
                $body = str_replace($tok, mb_substr($tok, 0, 6) . '…', $body);
            }
            return ['url' => $url, 'fields' => 'GET + Basic', 'http' => $res->status(), 'json' => is_array($json), 'token_ok' => (bool) $tok, 'body' => $body];
        } catch (\Throwable $e) {
            return ['url' => $url, 'fields' => 'GET + Basic', 'http' => null, 'json' => false, 'token_ok' => false, 'body' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    protected function probe(string $base, string $path, array $fields, string $pub, string $priv, bool $header = false): array
    {
        $url = $base . ltrim($path, '/');
        try {
            $res = Http::acceptJson()->asJson()->connectTimeout(4)->timeout(7)
                ->withHeaders($header ? ['X-API-KEY' => $pub] : [])
                ->post($url, [$fields[0] => $pub, $fields[1] => $priv]);
            $json = $res->json();
            $token = self::tokenFrom($json);
            $body = str_replace([$pub, $priv], '***', mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) $res->body()))), 0, 160));

            return ['url' => $url, 'fields' => implode('+', $fields) . ($header ? ' +X-API-KEY' : ''), 'http' => $res->status(), 'json' => is_array($json), 'token_ok' => is_string($token) && $token !== '', 'body' => $body];
        } catch (\Throwable $e) {
            return ['url' => $url, 'fields' => implode('+', $fields), 'http' => null, 'json' => false, 'token_ok' => false, 'body' => mb_substr($e->getMessage(), 0, 120)];
        }
    }
}
