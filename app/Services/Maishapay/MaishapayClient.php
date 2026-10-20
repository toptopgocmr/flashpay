<?php

namespace App\Services\Maishapay;

use App\Services\Remit\RemitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MaishaPay — API marchand (https://docs.maishapay.net, collection Postman « MaishaPay »).
 *
 * Base : https://marchand.maishapay.online/api — clés publique / secrète + gatewayMode (0 sandbox, 1 production)
 * envoyés dans le CORPS de chaque requête (format de la collection Postman).
 *
 *   POST collect/v2/store/mobileMoney          collecte mobile money (le client valide sur son téléphone)
 *   POST collect/v3/store/card                 page de paiement carte 3-D Secure (Visa, Mastercard, Amex) → paymentPage
 *   POST b2c/store/transfert/mobilemoney       versement vers un wallet mobile money
 *   POST transaction/rest/v2/check[?useRef=1]  statut (par transactionId MaishaPay, ou par notre référence)
 *   POST services/airtime/topup | services/paytv/pay | services/utility/pay   (doc Web API v2.1, à confirmer)
 *
 * Statuts : PENDING / PROCESSING → en cours ; SUCCESS / APPROVED → réussi ;
 * FAILED / DECLINED / CANCELLED / CANCELED / EXPIRED → échoué.
 * Les notifications (callbackUrl) ne sont jamais crues : le statut est toujours relu par check.
 */
class MaishapayClient
{
    /** Opérateurs mobile money → code provider MaishaPay. */
    public const PROVIDERS = ['AIRTEL' => 'AIRTEL', 'ORANGE' => 'ORANGE', 'MTN' => 'MTN', 'VODACOM' => 'MPESA', 'MPESA' => 'MPESA', 'AFRICELL' => 'AFRICELL', 'MOOV' => 'MOOV'];

    protected function cfg(string $k, $d = null)
    {
        return config('flashpay.maishapay.' . $k, $d);
    }

    public function enabled(): bool
    {
        return (bool) $this->cfg('enabled') && (bool) $this->cfg('public_key') && (bool) $this->cfg('secret_key');
    }

    /** Sandbox : MAISHAPAY_SANDBOX, sinon déduit de la clé (MP-LIVEPK-… = production). */
    public function sandbox(): bool
    {
        $s = $this->cfg('sandbox');
        if ($s !== null) {
            return (bool) $s;
        }
        return ! str_starts_with(strtoupper((string) $this->cfg('public_key')), 'MP-LIVE');
    }

    public function countries(): array
    {
        return array_values(array_filter((array) $this->cfg('countries', [])));
    }

    public function callbackUrl(): string
    {
        return url('/api/webhooks/maishapay');
    }

    /** Code provider MaishaPay d'un opérateur (« Airtel Money », « MTN », « Vodacom M-Pesa »…). */
    public static function provider(?string $operator): ?string
    {
        $op = strtoupper((string) $operator);
        foreach (['M-PESA' => 'MPESA', 'MPESA' => 'MPESA', 'VODACOM' => 'MPESA', 'AIRTEL' => 'AIRTEL', 'ORANGE' => 'ORANGE', 'MTN' => 'MTN', 'AFRICELL' => 'AFRICELL', 'MOOV' => 'MOOV'] as $needle => $code) {
            if (str_contains($op, $needle)) {
                return $code;
            }
        }
        return null;
    }

    public static function mapStatus(?string $raw): string
    {
        return match (strtoupper(trim((string) $raw))) {
            'SUCCESS', 'SUCCESSFUL', 'APPROVED', 'COMPLETED', 'PAID' => 'successful',
            'FAILED', 'FAILURE', 'DECLINED', 'CANCELLED', 'CANCELED', 'EXPIRED', 'REJECTED', 'ERROR' => 'failed',
            default => 'pending',
        };
    }

    /** Montant : unités entières (exemples MaishaPay : 100 CDF, 1 USD). */
    protected static function amount(int|float $a): string
    {
        return (string) (is_float($a) && floor($a) != $a ? round($a, 2) : (int) $a);
    }

    protected function base(string $reference): array
    {
        return [
            'transactionReference' => $reference,
            'gatewayMode' => $this->sandbox() ? '0' : '1',
            'publicApiKey' => (string) $this->cfg('public_key'),
            'secretApiKey' => (string) $this->cfg('secret_key'),
        ];
    }

    /**
     * Collecte mobile money.
     * @param array{phone:string, provider:string, amount:int, currency:string, name?:?string, email?:?string} $p
     * @return array{id:?string, status:string, raw_status:?string, raw:array, sent:array}
     */
    public function collectMobile(string $reference, array $p): array
    {
        $body = $this->base($reference) + [
            'order' => array_filter([
                'amount' => self::amount($p['amount']),
                'currency' => strtoupper($p['currency']),
                'customerFullName' => mb_substr(trim((string) ($p['name'] ?? '')) ?: 'Client FlashPay', 0, 100),
                'customerEmailAdress' => $p['email'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'paymentChannel' => [
                'channel' => 'MOBILEMONEY',
                'provider' => $p['provider'],
                'walletID' => '+' . preg_replace('/\D/', '', $p['phone']),
                'callbackUrl' => $this->callbackUrl(),
            ],
        ];
        return $this->operation('collect_mobile', $body);
    }

    /**
     * Page de paiement carte (3-D Secure).
     * @param array{amount:int, currency:string, brand?:?string, first_name?:?string, last_name?:?string, phone?:?string, email?:?string, address?:?string, city?:?string, return_url:string} $p
     * @return array{id:?string, status:string, raw_status:?string, raw:array, sent:array, url:?string}
     */
    public function collectCard(string $reference, array $p): array
    {
        $brand = strtoupper((string) ($p['brand'] ?? ''));
        $body = $this->base($reference) + [
            'order' => [
                'amount' => self::amount($p['amount']),
                'currency' => strtoupper($p['currency']),
                'customerFirstname' => $p['first_name'] ?: 'Client',
                'customerLastname' => $p['last_name'] ?: 'FlashPay',
                'customerAddress' => $p['address'] ?: 'Brazzaville',
                'customerCity' => $p['city'] ?: 'Brazzaville',
                'customerPhoneNumber' => '+' . preg_replace('/\D/', '', (string) ($p['phone'] ?? '')),
                'customerEmailAdress' => $p['email'] ?: config('flashpay.digitwace.default_email', 'clients@flashpay.cg'),
            ],
            'paymentChannel' => [
                'channel' => 'CARD',
                'provider' => str_contains($brand, 'MASTER') ? 'MASTERCARD' : (str_contains($brand, 'AMEX') || str_contains($brand, 'AMERICAN') ? 'AMERICAN EXPRESS' : 'VISA'),
                'callbackUrl' => $p['return_url'],
            ],
        ];
        $r = $this->operation('collect_card', $body);
        $r['url'] = collect(['paymentPage', 'data.paymentPage', 'payment_page', 'data.payment_page', 'redirectUrl', 'data.redirectUrl'])
            ->map(fn ($k) => data_get($r['raw'], $k))->first(fn ($v) => is_string($v) && str_starts_with($v, 'http'));
        return $r;
    }

    /**
     * Page de paiement crypto (Bitcoin, Ethereum, USDT…) — « Collection via Crypto Money ».
     * Format calqué sur la collecte carte v3 (même order, paymentChannel CRYPTO) tant que
     * MaishaPay n'a pas publié la spécification : chemin et provider ajustables par variables.
     * @param array{amount:int, currency:string, asset?:?string, first_name?:?string, last_name?:?string, phone?:?string, email?:?string, return_url:string} $p
     * @return array{id:?string, status:string, raw_status:?string, raw:array, sent:array, url:?string}
     */
    public function collectCrypto(string $reference, array $p): array
    {
        $body = $this->base($reference) + [
            'order' => [
                'amount' => self::amount($p['amount']),
                'currency' => strtoupper($p['currency']),
                'customerFirstname' => $p['first_name'] ?: 'Client',
                'customerLastname' => $p['last_name'] ?: 'FlashPay',
                'customerPhoneNumber' => '+' . preg_replace('/\D/', '', (string) ($p['phone'] ?? '')),
                'customerEmailAdress' => $p['email'] ?: config('flashpay.digitwace.default_email', 'clients@flashpay.cg'),
            ],
            'paymentChannel' => array_filter([
                'channel' => 'CRYPTO',
                'provider' => strtoupper((string) ($p['asset'] ?? '')) ?: null,
                'callbackUrl' => $p['return_url'],
            ]),
        ];
        $r = $this->operation('collect_crypto', $body);
        $r['url'] = collect(['paymentPage', 'data.paymentPage', 'payment_page', 'data.payment_page', 'redirectUrl', 'data.redirectUrl', 'paymentUrl', 'data.paymentUrl'])
            ->map(fn ($k) => data_get($r['raw'], $k))->first(fn ($v) => is_string($v) && str_starts_with($v, 'http'));
        return $r;
    }

    /**
     * Versement vers un wallet mobile money (B2C).
     * @param array{phone:string, provider:string, amount:int, currency:string, name?:?string, motif?:?string} $p
     */
    public function payoutMobile(string $reference, array $p): array
    {
        $body = $this->base($reference) + [
            'order' => [
                'motif' => mb_substr((string) ($p['motif'] ?? 'FlashPay'), 0, 100),
                'amount' => self::amount($p['amount']),
                'currency' => strtoupper($p['currency']),
                'customerFullName' => mb_substr(trim((string) ($p['name'] ?? '')) ?: 'Beneficiaire FlashPay', 0, 100),
                'customerEmailAdress' => '',
            ],
            'paymentChannel' => [
                'provider' => $p['provider'],
                'walletID' => '+' . preg_replace('/\D/', '', $p['phone']),
                'callbackUrl' => $this->callbackUrl(),
            ],
        ];
        return $this->operation('payout_mobile', $body);
    }

    /**
     * Services (crédit, TV, factures) : corps de la doc Web API v2.1 + clés MaishaPay.
     * @param 'airtime'|'paytv'|'utility' $service
     */
    public function service(string $service, string $reference, array $fields): array
    {
        $body = $this->base($reference) + ['reference' => $reference] + $fields + ['callback_url' => $this->callbackUrl()];
        return $this->operation($service, $body);
    }

    /**
     * Statut d'une opération : par identifiant MaishaPay, ou par notre référence (useRef=1).
     * @return array{status:string, raw_status:?string, raw:array, failure:?string, found:bool}
     */
    public function check(string $id, bool $byReference = false): array
    {
        $body = $this->base($id);
        unset($body['transactionReference']);
        $body['transactionId'] = $id;
        $path = (string) $this->cfg('paths.check', 'transaction/rest/v2/check') . ($byReference ? '?useRef=1' : '');
        try {
            $json = $this->send($path, $body);
        } catch (RemitException $e) {
            if (in_array($e->httpStatus, [404], true) || preg_match('/not\s*found|introuvable|n\'existe/i', $e->getMessage())) {
                return ['status' => 'pending', 'raw_status' => 'NOT_FOUND', 'raw' => $e->response, 'failure' => null, 'found' => false];
            }
            throw $e;
        }
        $raw = self::statusOf($json);
        return ['status' => self::mapStatus($raw), 'raw_status' => $raw, 'raw' => $json, 'failure' => self::failureOf($json, $raw), 'found' => $raw !== null];
    }

    /** Statut brut dans une réponse MaishaPay (racine ou data). */
    public static function statusOf(array $json): ?string
    {
        foreach (['transactionStatus', 'data.transactionStatus', 'payment_status', 'data.payment_status', 'data.status'] as $k) {
            $v = data_get($json, $k);
            if (is_string($v) && trim($v) !== '') {
                return strtoupper(trim($v));
            }
        }
        $d = data_get($json, 'description') ?? data_get($json, 'data.description');
        return is_string($d) && in_array(strtoupper($d), ['APPROVED', 'DECLINED', 'CANCELED', 'CANCELLED'], true) ? strtoupper($d) : null;
    }

    public static function idOf(array $json): ?string
    {
        foreach (['transactionId', 'data.transactionId', 'data.transaction_id', 'transaction_id', 'transactionRefId'] as $k) {
            $v = data_get($json, $k);
            if (is_scalar($v) && (string) $v !== '') {
                return (string) $v;
            }
        }
        return null;
    }

    protected static function failureOf(array $json, ?string $raw): ?string
    {
        if (self::mapStatus($raw) !== 'failed') {
            return null;
        }
        $m = data_get($json, 'transactionDescription') ?? data_get($json, 'data.transactionDescription') ?? data_get($json, 'message') ?? data_get($json, 'data.message');
        return is_string($m) && $m !== '' ? mb_substr($m, 0, 200) : $raw;
    }

    /** Envoi d'une opération : refus explicite (FAILED / DECLINED dans la réponse) → exception définitive. */
    protected function operation(string $pathKey, array $body): array
    {
        $json = $this->send((string) $this->cfg('paths.' . $pathKey), $body);
        $raw = self::statusOf($json);
        if (self::mapStatus($raw) === 'failed') {
            throw new RemitException('MaishaPay : ' . (self::failureOf($json, $raw) ?: 'opération refusée'), $raw, null, $json, true);
        }
        return ['id' => self::idOf($json), 'status' => self::mapStatus($raw), 'raw_status' => $raw, 'raw' => $json, 'sent' => self::redact($body)];
    }

    public static function redact(array $body): array
    {
        array_walk_recursive($body, function (&$v, $k) {
            if (in_array($k, ['publicApiKey', 'secretApiKey'], true)) {
                $v = '•••';
            } elseif (is_scalar($v) && preg_match('/walletID|phone|msisdn/i', (string) $k)) {
                $v = (string) $v;
                $v = strlen($v) > 6 ? substr($v, 0, 5) . str_repeat('•', strlen($v) - 7) . substr($v, -2) : '•••';
            }
        });
        return $body;
    }

    public function send(string $path, array $body): array
    {
        if (! $this->enabled()) {
            throw new RemitException('MaishaPay non configuré (MAISHAPAY_ENABLED, MAISHAPAY_PUBLIC_KEY, MAISHAPAY_SECRET_KEY).', null, null, [], true);
        }
        $url = rtrim((string) $this->cfg('base_url'), '/') . '/' . ltrim($path, '/');
        $req = Http::acceptJson()->asJson()->timeout((int) $this->cfg('timeout', 40));
        if ($t = (string) $this->cfg('bearer')) {
            $req = $req->withToken($t)->withHeaders(['X-MaishaPay-Version' => '2024-01-01']);
        }
        try {
            $res = $req->post($url, $body);
        } catch (ConnectionException $e) {
            throw new RemitException('MaishaPay injoignable : ' . $e->getMessage());
        }
        $json = (array) ($res->json() ?? []);
        Log::info('MaishaPay POST ' . $path, array_filter(['http' => $res->status(), 'status' => self::statusOf($json), 'id' => self::idOf($json),
            'body' => mb_substr((string) $res->body(), 0, 1500), 'sent' => self::redact($body)]));
        if ($res->successful()) {
            return $json;
        }
        $msg = (string) (data_get($json, 'message') ?? data_get($json, 'error') ?? data_get($json, 'data.message') ?? $res->reason());
        $raw = self::statusOf($json);
        // 4xx avec statut FAILED / DECLINED = refus certain ; 4xx sans statut = données refusées (rien exécuté)
        throw new RemitException('MaishaPay (HTTP ' . $res->status() . ') : ' . mb_substr($msg, 0, 200), $raw, $res->status(), $json,
            self::mapStatus($raw) === 'failed' || ($res->status() >= 400 && $res->status() < 500 && ! in_array($res->status(), [408, 409, 429], true)));
    }

    /** Signature d'un webhook (X-MaishaPay-Signature : sha256=HMAC-SHA256 du corps brut). */
    public function validSignature(string $rawBody, ?string $header): ?bool
    {
        $secret = (string) $this->cfg('webhook_secret');
        if ($secret === '' || ! $header) {
            return null; // non signé / non configuré : on revérifie de toute façon par check
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, str_starts_with($header, 'sha256=') ? $header : 'sha256=' . $header);
    }
}
