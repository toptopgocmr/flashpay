<?php

namespace App\Services\Fincra;

use App\Services\Remit\RemitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fincra — API (https://docs.fincra.com).
 *
 * Base : https://sandboxapi.fincra.com (sandbox) / https://api.fincra.com (production).
 * En-têtes : api-key (clé secrète), x-pub-key (clé publique), x-business-id (identifiant entreprise).
 *
 *   POST /checkout/charges                                      collecte (type mobile_money) → le client valide sur son téléphone
 *   POST /checkout/charges/{id}/authorize                       code OTP quand data.auth_model = OTP
 *   GET  /checkout/charges/verify/{reference}                   statut d'une collecte (notre référence)
 *   POST /disbursements/payouts                                 versement (mobile_money_wallet, bank_account)
 *   GET  /disbursements/payouts/customer-reference/{ref}        statut d'un versement (notre référence)
 *   POST /quotes/generate                                       devis de conversion (versement multi-devises, 30 s)
 *   GET  /core/banks?country=&currency=&paymentDestination=     opérateurs mobile money (mobileMoneyCode) et banques
 *   GET  /wallets?businessID=&currency=                          soldes
 *
 * Statuts : collecte pending / initiated → en cours ; success / successful → réussi ; failed / expired → échoué.
 *           versement processing / pending → en cours ; successful → réussi ; failed → échoué.
 * Les webhooks (charge.successful, payout.successful, payout.failed) ne sont jamais crus :
 * le statut est toujours relu par l'API avant de valider ou de rembourser.
 */
class FincraClient
{
    /** Mots-clés d'opérateur FlashPay → code opérateur Fincra (collecte : « operator », versement : « mobileMoneyCode »). */
    public const OPERATORS = [
        'M-PESA' => 'SAFARICOM', 'MPESA' => 'SAFARICOM', 'SAFARICOM' => 'SAFARICOM', 'VODAFONE' => 'VODAFONE', 'AIRTELTIGO' => 'AIRTELTIGO',
        'AIRTEL' => 'AIRTEL', 'ORANGE' => 'ORANGE', 'MTN' => 'MTN', 'MOOV' => 'MOOV', 'FREE' => 'FREE', 'WAVE' => 'WAVE', 'AFRICELL' => 'AFRICELL',
    ];

    protected function cfg(string $k, $d = null)
    {
        return config('flashpay.fincra.' . $k, $d);
    }

    public function enabled(): bool
    {
        return (bool) $this->cfg('enabled') && (bool) $this->cfg('secret_key') && (bool) $this->cfg('business_id');
    }

    /** Sandbox tant que l'URL n'est pas celle de production (api.fincra.com). */
    public function sandbox(): bool
    {
        return str_contains((string) $this->cfg('base_url'), 'sandbox');
    }

    public function countries(): array
    {
        return array_values(array_filter((array) $this->cfg('countries', [])));
    }

    public function remitCountries(): array
    {
        return array_values(array_filter((array) $this->cfg('remit_countries', [])));
    }

    public function businessId(): string
    {
        return (string) $this->cfg('business_id');
    }

    public function webhookUrl(): string
    {
        return url('/api/webhooks/fincra');
    }

    /** Code opérateur Fincra d'un nom d'opérateur FlashPay (« MTN Mobile Money », « Airtel Money », « Vodacom M-Pesa »…). */
    public static function operator(?string $name): ?string
    {
        $op = strtoupper((string) $name);
        foreach (self::OPERATORS as $needle => $code) {
            if ($op !== '' && str_contains($op, $needle)) {
                return $code;
            }
        }
        return null;
    }

    public static function mapStatus(?string $raw): string
    {
        return match (strtolower(trim((string) $raw))) {
            'success', 'successful', 'completed', 'paid', 'approved' => 'successful',
            'failed', 'failure', 'expired', 'cancelled', 'canceled', 'declined', 'rejected', 'reversed' => 'failed',
            default => 'pending',
        };
    }

    /** Montant : nombre ; entier quand il n'a pas de décimales (Airtel exige un entier). */
    protected static function amount(int|float $a): int|float
    {
        return is_float($a) && floor($a) != $a ? round($a, 2) : (int) $a;
    }

    /** Numéro mobile money : indicatif + numéro, sans « + » (doc v3), sauf FINCRA_MSISDN_PLUS=true. */
    public function msisdn(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone);
        return $this->cfg('msisdn_plus') ? '+' . $d : $d;
    }

    /**
     * Collecte mobile money (Direct Charge).
     * @param array{phone:string, operator:string, amount:int, currency:string, name?:?string, email?:?string} $p
     * @return array{id:?string, status:string, raw_status:?string, raw:array, sent:array, charge_id:?string, message:?string, auth_model:?string}
     */
    public function charge(string $reference, array $p): array
    {
        $body = [
            'type' => 'mobile_money',
            'amount' => self::amount($p['amount']),
            'currency' => strtoupper($p['currency']),
            'reference' => $reference,
            'phone' => '+' . preg_replace('/\D/', '', $p['phone']),
            'operator' => $p['operator'],
            'customer' => array_filter([
                'name' => mb_substr(trim((string) ($p['name'] ?? '')) ?: 'Client FlashPay', 0, 100),
                'email' => $p['email'] ?: (string) $this->cfg('default_email'),
                'phoneNumber' => '+' . preg_replace('/\D/', '', $p['phone']),
            ]),
            'settlementDestination' => 'wallet',
            'webhookUrl' => $this->webhookUrl(),
        ];
        $json = $this->request('post', '/checkout/charges', $body);
        $raw = self::statusOf($json);
        if (self::mapStatus($raw) === 'failed' || data_get($json, 'success') === false || data_get($json, 'status') === false) {
            throw new RemitException('Fincra : ' . (self::messageOf($json) ?: 'collecte refusée'), $raw, null, $json, true);
        }
        return [
            'id' => self::idOf($json), 'status' => self::mapStatus($raw), 'raw_status' => $raw, 'raw' => $json, 'sent' => self::redact($body),
            'charge_id' => is_scalar($c = data_get($json, 'data.id')) && (string) $c !== '' ? (string) $c : null,
            'message' => is_string($m = data_get($json, 'data.message')) ? $m : null,
            'auth_model' => is_string($a = data_get($json, 'data.auth_model') ?? data_get($json, 'data.authModel')) ? strtoupper($a) : null,
        ];
    }

    /** Code OTP saisi par le client (data.auth_model = OTP). */
    public function authorizeCharge(string $chargeId, string $otp): array
    {
        return $this->request('post', '/checkout/charges/' . rawurlencode($chargeId) . '/authorize', ['otp' => $otp]);
    }

    /**
     * Statut d'une collecte par notre référence.
     * @return array{status:string, raw_status:?string, raw:array, failure:?string, found:bool}
     */
    public function verifyCharge(string $reference): array
    {
        return $this->lookup('/checkout/charges/verify/' . rawurlencode($reference));
    }

    /**
     * Versement (mobile money ou compte bancaire).
     * @param array{amount:int|float, source_currency:string, destination_currency:string, destination:string, beneficiary:array, description?:?string, quote_reference?:?string, payment_scheme?:?string} $p
     * @return array{id:?string, status:string, raw_status:?string, raw:array, sent:array, fee:?float}
     */
    public function payout(string $customerReference, array $p): array
    {
        $body = array_filter([
            'business' => $this->businessId(),
            'sourceCurrency' => strtoupper($p['source_currency']),
            'destinationCurrency' => strtoupper($p['destination_currency']),
            'amount' => self::amount($p['amount']),
            'description' => mb_substr((string) ($p['description'] ?? 'FlashPay'), 0, 100),
            'customerReference' => $customerReference,
            'paymentDestination' => $p['destination'],
            'quoteReference' => $p['quote_reference'] ?? null,
            'paymentScheme' => $p['payment_scheme'] ?? null,
            'beneficiary' => array_filter($p['beneficiary'], fn ($v) => $v !== null && $v !== '' && $v !== []),
        ], fn ($v) => $v !== null && $v !== '');
        $json = $this->request('post', '/disbursements/payouts', $body);
        $raw = self::statusOf($json);
        if (self::mapStatus($raw) === 'failed' || data_get($json, 'success') === false) {
            throw new RemitException('Fincra : ' . (self::messageOf($json) ?: 'versement refusé'), $raw, null, $json, true);
        }
        $fee = data_get($json, 'data.fee');
        return ['id' => self::idOf($json), 'status' => self::mapStatus($raw), 'raw_status' => $raw, 'raw' => $json, 'sent' => self::redact($body),
            'fee' => is_numeric($fee) ? (float) $fee : null];
    }

    /** Statut d'un versement par notre référence (customerReference). */
    public function payoutStatus(string $customerReference): array
    {
        return $this->lookup('/disbursements/payouts/customer-reference/' . rawurlencode($customerReference));
    }

    /**
     * Devis de conversion pour un versement multi-devises (valable 30 s).
     * @return array{reference:string, source_amount:?float, destination_amount:?float, rate:?float, fee:?float, raw:array}
     */
    public function quote(string $from, string $to, int|float $amount, string $destination, string $beneficiaryType = 'individual', string $action = 'receive', ?string $scheme = null): array
    {
        $json = $this->request('post', '/quotes/generate', array_filter([
            'sourceCurrency' => strtoupper($from), 'destinationCurrency' => strtoupper($to), 'amount' => (string) self::amount($amount),
            'action' => $action, 'transactionType' => 'disbursement', 'business' => $this->businessId(),
            'feeBearer' => (string) $this->cfg('fee_bearer', 'business'), 'paymentDestination' => $destination,
            'beneficiaryType' => $beneficiaryType, 'paymentScheme' => $scheme,
        ]));
        $ref = data_get($json, 'data.reference') ?? data_get($json, 'reference');
        if (! is_string($ref) || $ref === '') {
            throw new RemitException('Fincra : devis de conversion ' . strtoupper($from) . ' → ' . strtoupper($to) . ' indisponible', null, null, $json, true);
        }
        $num = fn (string ...$keys) => collect($keys)->map(fn ($k) => data_get($json, 'data.' . $k))->first(fn ($v) => is_numeric($v));
        return ['reference' => $ref, 'source_amount' => ($v = $num('sourceAmount', 'amountToCharge')) !== null ? (float) $v : null,
            'destination_amount' => ($v = $num('destinationAmount', 'amountToReceive')) !== null ? (float) $v : null,
            'rate' => ($v = $num('rate', 'initialRate')) !== null ? (float) $v : null, 'fee' => ($v = $num('fee')) !== null ? (float) $v : null, 'raw' => $json];
    }

    /**
     * Opérateurs mobile money / banques d'un pays (mis en cache 6 h).
     * @return list<array{id:int, code:string, name:string}>
     */
    public function institutions(string $iso, string $destination = 'mobile_money_wallet', ?string $currency = null): array
    {
        $iso = strtoupper($iso);
        return Cache::remember("fincra:banks:{$iso}:{$destination}:{$currency}", now()->addHours(6), function () use ($iso, $destination, $currency) {
            $json = $this->request('get', '/core/banks', array_filter(['country' => $iso, 'currency' => $currency, 'paymentDestination' => $destination]));
            $rows = data_get($json, 'data');
            return collect(is_array($rows) ? $rows : [])->filter(fn ($r) => is_array($r) && ! empty($r['code']))
                ->map(fn ($r) => ['id' => (int) ($r['id'] ?? 0), 'code' => (string) $r['code'], 'name' => (string) ($r['name'] ?? $r['code'])])->values()->all();
        });
    }

    /**
     * Code mobileMoneyCode Fincra pour l'opérateur d'un numéro : liste officielle du pays si lisible,
     * sinon code générique (MTN, AIRTEL, ORANGE…).
     */
    public function mobileMoneyCode(string $iso, ?string $operator, ?string $currency = null): ?string
    {
        $generic = self::operator($operator);
        try {
            $list = $this->institutions($iso, 'mobile_money_wallet', $currency);
        } catch (\Throwable) {
            $list = [];
        }
        foreach ($list as $i) {
            $code = strtoupper($i['code']);
            if ($generic && ($code === $generic || self::operator($i['name']) === $generic || self::operator($code) === $generic)) {
                return $i['code'];
            }
        }
        return $generic;
    }

    /** @return list<array{currency:string, available:?float, ledger:?float}> */
    public function balances(): array
    {
        $json = $this->request('get', '/wallets', ['businessID' => $this->businessId()]);
        $rows = data_get($json, 'data');
        $rows = is_array($rows) && array_is_list($rows) ? $rows : (is_array($rows) ? [$rows] : []);
        return collect($rows)->filter(fn ($r) => is_array($r) && ! empty($r['currency']))->map(fn ($r) => [
            'currency' => strtoupper((string) $r['currency']),
            'available' => is_numeric($v = $r['availableBalance'] ?? $r['available_balance'] ?? $r['balance'] ?? null) ? (float) $v : null,
            'ledger' => is_numeric($v = $r['ledgerBalance'] ?? $r['ledger_balance'] ?? null) ? (float) $v : null,
        ])->values()->all();
    }

    /** Solde disponible dans cette devise (mis en cache 60 s) ; null si inconnu (ne bloque pas). */
    public function availableFor(string $currency): ?float
    {
        if (! $this->enabled() || ! $this->cfg('check_balance', true)) {
            return null;
        }
        try {
            $list = Cache::remember('fincra:balances', 60, fn () => $this->balances());
        } catch (\Throwable) {
            return null;
        }
        foreach ($list as $b) {
            if ($b['currency'] === strtoupper($currency)) {
                return $b['available'];
            }
        }
        return null;
    }

    /**
     * Signature d'un webhook : en-tête « signature » = HMAC-SHA512 (hex) du corps JSON avec le webhook secret.
     * true / false ; null si aucun secret configuré (contrôle impossible — le statut est de toute façon relu).
     */
    public function validSignature(string $body, ?string $signature): ?bool
    {
        $secret = (string) $this->cfg('webhook_secret');
        if ($secret === '') {
            return null;
        }
        if (! $signature) {
            return false;
        }
        $candidates = [$body];
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            // Exemples Fincra : JSON.stringify(payload) — on accepte aussi la forme réencodée sans échappement
            $candidates[] = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $candidates[] = json_encode($decoded);
        }
        foreach ($candidates as $c) {
            if (is_string($c) && hash_equals(hash_hmac('sha512', $c, $secret), strtolower(trim($signature)))) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ interne

    /** Lecture d'un statut : 404 / « not found » → introuvable (found = false). */
    protected function lookup(string $path): array
    {
        try {
            $json = $this->request('get', $path);
        } catch (RemitException $e) {
            if ($e->httpStatus === 404 || preg_match('/not\s*found|does not exist|introuvable|no (transaction|payout|charge)/i', $e->getMessage())) {
                return ['status' => 'pending', 'raw_status' => 'NOT_FOUND', 'raw' => $e->response, 'failure' => null, 'found' => false];
            }
            throw $e;
        }
        $raw = self::statusOf($json);
        $status = self::mapStatus($raw);
        return ['status' => $status, 'raw_status' => $raw, 'raw' => $json, 'found' => $raw !== null,
            'failure' => $status === 'failed' ? (self::reasonOf($json) ?: $raw) : null];
    }

    public static function statusOf(array $json): ?string
    {
        foreach (['data.status', 'data.data.status', 'status'] as $k) {
            $v = data_get($json, $k);
            if (is_string($v) && trim($v) !== '') {
                return strtolower(trim($v));
            }
        }
        return null;
    }

    public static function idOf(array $json): ?string
    {
        foreach (['data.reference', 'data.id', 'data.data.reference', 'data.data.id'] as $k) {
            $v = data_get($json, $k);
            if (is_scalar($v) && (string) $v !== '') {
                return (string) $v;
            }
        }
        return null;
    }

    protected static function reasonOf(array $json): ?string
    {
        foreach (['data.reason', 'data.failureReason', 'data.message', 'data.data.reason', 'message'] as $k) {
            $v = data_get($json, $k);
            if (is_string($v) && trim($v) !== '') {
                return mb_substr(trim($v), 0, 200);
            }
        }
        return null;
    }

    protected static function messageOf(array $json): ?string
    {
        foreach (['error', 'message', 'errors.0.message', 'data.message', 'data.reason'] as $k) {
            $v = data_get($json, $k);
            if (is_string($v) && trim($v) !== '') {
                return mb_substr(trim($v), 0, 200);
            }
            if (is_array($v) && $v) {
                return mb_substr(json_encode($v, JSON_UNESCAPED_UNICODE), 0, 200);
            }
        }
        return null;
    }

    public static function redact(array $body): array
    {
        array_walk_recursive($body, function (&$v, $k) {
            if (is_scalar($v) && preg_match('/^(phone|phoneNumber|accountNumber|iban|otp)$/i', (string) $k)) {
                $v = (string) $v;
                $v = strlen($v) > 6 ? substr($v, 0, 5) . str_repeat('•', strlen($v) - 7) . substr($v, -2) : '•••';
            }
        });
        return $body;
    }

    public function request(string $method, string $path, array $data = []): array
    {
        if (! $this->enabled()) {
            throw new RemitException('Fincra non configuré (FINCRA_ENABLED, FINCRA_SECRET_KEY, FINCRA_BUSINESS_ID).', null, null, [], true);
        }
        $url = rtrim((string) $this->cfg('base_url'), '/') . '/' . ltrim($path, '/');
        $req = Http::acceptJson()->timeout((int) $this->cfg('timeout', 40))->withHeaders(array_filter([
            'api-key' => (string) $this->cfg('secret_key'),
            'x-pub-key' => (string) $this->cfg('public_key'),
            'x-business-id' => $this->businessId(),
        ]));
        try {
            $res = $method === 'get' ? $req->get($url, $data) : $req->asJson()->post($url, $data);
        } catch (ConnectionException $e) {
            throw new RemitException('Fincra injoignable : ' . $e->getMessage(), null, null, [], false);
        }
        $json = (array) ($res->json() ?? []);
        if ($res->failed()) {
            $msg = self::messageOf($json) ?: 'HTTP ' . $res->status();
            Log::warning('Fincra : erreur API', ['path' => $path, 'status' => $res->status(), 'message' => $msg]);
            if (in_array($res->status(), [401, 403], true)) {
                $msg = 'clés API refusées (' . $msg . ')';
            }
            $code = is_string($t = $json['errorType'] ?? null) ? mb_substr($t, 0, 60) : null;
            // DUPLICATE_CUSTOMER_REFERENCE : déjà reçu par Fincra → issue incertaine, on relit le statut
            $duplicate = str_contains(strtoupper((string) $code . ' ' . $msg), 'DUPLICATE');
            throw new RemitException('Fincra : ' . $msg, $code, $duplicate ? 409 : $res->status(), $json, false);
        }
        return $json;
    }
}
