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
            $f = $this->cfg('fields.login');
            $res = $this->http()->post($this->path('login'), [
                $f['public_key'] => $this->cfg('public_key'),
                $f['private_key'] => $this->cfg('private_key'),
            ]);
            $json = $res->json() ?? [];
            $token = data_get($json, 'token') ?? data_get($json, 'access_token') ?? data_get($json, 'data.token') ?? data_get($json, 'data.access_token');
            if (! $res->successful() || ! $token) {
                Log::warning('WacePay connexion refusée', ['http' => $res->status(), 'url' => $this->cfg('base_url') . $this->path('login'), 'body' => mb_substr((string) $res->body(), 0, 300)]);
                throw new DigitwaceException('Connexion WacePay refusée : ' . $this->errorText($json, $res->status()), $this->codeOf($json));
            }
            Cache::put($key, $token, now()->addMinutes((int) $this->cfg('token_ttl_minutes', 55)));
            return $token;
        });
    }

    // ------------------------------------------------------------ Référentiels

    /** Codes payeurs (opérateurs / réseaux de paiement par pays) — cache 24 h. */
    public function payerCodes(?string $country = null): array
    {
        $key = 'digitwace:payers:' . ($country ?: 'all');
        return Cache::remember($key, now()->addHours((int) $this->cfg('payer_cache_hours', 24)), function () use ($country) {
            $json = $this->call('get', 'payer_codes', $country ? [$this->cfg('fields.country') => $country] : []);
            return (array) (data_get($json, 'data') ?? data_get($json, 'payers') ?? $json);
        });
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
            $pick = $rows->first(fn ($r) => $operator && str_contains(strtoupper((string) $r->payer_name), strtoupper($operator)))
                ?? $rows->firstWhere('method', 'wallet') ?? $rows->first();
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
            if ($code && $pc === strtoupper($country) && (! $operator || str_contains($name, strtoupper($operator)))) {
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
    public function payout(string $reference, string $senderCode, string $beneficiaryCode, string $payerCode, int $amount, string $currency, string $phone, ?string $purpose = null): array
    {
        $f = $this->cfg('fields.transaction');

        return Cache::lock('digitwace:queue', 60)->block((int) $this->cfg('queue_wait_seconds', 45), function () use ($f, $reference, $senderCode, $beneficiaryCode, $payerCode, $amount, $currency, $phone, $purpose) {
            $created = $this->call('post', 'wallet', array_filter([
                $f['reference'] => $reference,
                $f['sender_code'] => $senderCode,
                $f['beneficiary_code'] => $beneficiaryCode,
                $f['payer_code'] => $payerCode,
                $f['amount'] => $amount,
                $f['currency'] => $currency,
                $f['wallet_number'] => preg_replace('/\D/', '', $phone),
                $f['purpose'] => $purpose ?: $this->cfg('default_purpose'),
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
     * Collecte (PAYIN) : demande de paiement envoyée au wallet mobile money du
     * client, qui valide avec son code secret. Asynchrone : statut par webhook / status().
     *
     * @return array{status:string, wace_id:?string, raw:array}
     */
    public function payin(string $reference, string $payerCode, int $amount, string $currency, string $phone, string $name, ?string $country = null): array
    {
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
    public function checkout(string $method, string $reference, int $amount, string $currency, array $customer, string $returnUrl): array
    {
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

    /** @return array{status:string, raw_status:?string, message:?string, raw:array} */
    public function status(string $waceIdOrReference): array
    {
        $json = $this->call('get', 'status', [$this->cfg('fields.transaction.transaction_code') => $waceIdOrReference]);
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

    public static function normalize(mixed $s): string
    {
        $s = strtolower(trim((string) $s));
        return match (true) {
            in_array($s, ['success', 'successful', 'succeeded', 'paid', 'completed', 'complete', 'done', 'approved', 'delivered', 'confirmed_paid'], true) => 'successful',
            in_array($s, ['failed', 'failure', 'rejected', 'declined', 'canceled', 'cancelled', 'error', 'refunded', 'expired', 'reversed'], true) => 'failed',
            default => 'pending',
        };
    }

    // ------------------------------------------------------------ HTTP

    /** Appel authentifié ; un 401 renouvelle le jeton une fois. */
    public function call(string $method, string $endpoint, array $data = []): array
    {
        $do = fn (string $token) => $method === 'get'
            ? $this->http()->withToken($token)->get($this->path($endpoint), $data)
            : $this->http()->withToken($token)->post($this->path($endpoint), $data);

        $res = $do($this->token());
        if ($res->status() === 401) {
            $res = $do($this->token(fresh: true));
        }
        $json = $res->json() ?? [];
        $code = $this->codeOf($json);

        Log::info('WacePay ' . strtoupper($method) . ' ' . $endpoint, [
            'http' => $res->status(), 'code' => $code, 'ref' => $data[$this->cfg('fields.transaction.reference')] ?? null,
        ]);

        if (! $res->successful() || ($code !== null && $code !== self::OK)) {
            throw new DigitwaceException('WacePay : ' . $this->errorText($json, $res->status()), $code, $res->status(), $json);
        }

        return $json;
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
            'login_fields' => array_values($this->cfg('fields.login')),
            'api_key_header' => (bool) $this->cfg('send_api_key_header'),
        ];
        $f = $this->cfg('fields.login');
        $t = microtime(true);
        try {
            $res = $this->http()->timeout(20)->post($this->path('login'), [$f['public_key'] => $pub, $f['private_key'] => $priv]);
            $body = (string) $res->body();
            foreach (array_filter([$pub, $priv]) as $secret) {
                $body = str_replace($secret, '***', $body);
            }
            $json = $res->json();
            $token = is_array($json) ? (data_get($json, 'token') ?? data_get($json, 'access_token') ?? data_get($json, 'data.token') ?? data_get($json, 'data.access_token')) : null;
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
        } catch (\Throwable $e) {
            $out += ['http' => null, 'ms' => (int) round((microtime(true) - $t) * 1000), 'token_ok' => false,
                'message' => 'Connexion impossible : ' . mb_substr($e->getMessage(), 0, 300)];
        }
        return $out;
    }

    protected function path(string $endpoint): string
    {
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
        return config('flashpay.digitwace.' . $key, $default);
    }
}
