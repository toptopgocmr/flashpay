<?php

namespace App\Services\Remit;

use App\Models\RemitParty;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WacePay — API de remittance v2.0.0 (spécification du 10/02/2025).
 *
 *   POST /login                                     { email, password } → access_token (3600 s)
 *   POST /sender/create, /beneficiary/create        → Code (réutilisé via la table remit_parties)
 *   POST /transaction/payouts/services              services par pays / devise (ServiceCode, ServiceName)
 *   POST /transaction/payercode                     { toCountry, payoutService, toCurrency } → PayerCode
 *   POST /transaction/bank/list                     { iso2, payercode } → banques (BankID)
 *   POST /transaction/cash/collection_code_by_country → points de retrait
 *   POST /transaction/wallet|bank|cash/create       → transaction.reference (WPPX…)
 *   GET  /transaction/status/{ref}                  PENDING | WAITING CONFIRMATION | PROCESSING | LOCKED | PAID | CANCEL
 *   POST /transaction/confirm                       { reference }
 *   GET  /transaction/relation | origin_fund/{type} | reason/{type} ; GET /account/balance
 */
class WacepayRemitClient
{
    protected function cfg(string $k, $d = null)
    {
        return config('flashpay.wacepay_remit.' . $k, $d);
    }

    public function enabled(): bool
    {
        return (bool) $this->cfg('enabled') && $this->cfg('email') && $this->cfg('password');
    }

    public function sandbox(): bool
    {
        return str_contains((string) $this->cfg('base_url'), 'sandbox');
    }

    // ------------------------------------------------------------ Authentification

    public function token(bool $fresh = false): string
    {
        $key = 'wacepay_remit:token:' . md5((string) $this->cfg('base_url') . $this->cfg('email'));
        if ($fresh) {
            Cache::forget($key);
        }
        if ($t = Cache::get($key)) {
            return $t;
        }
        $json = $this->send('post', 'login', ['email' => $this->cfg('email'), 'password' => $this->cfg('password')], auth: false);
        $t = (string) (data_get($json, 'access_token') ?? data_get($json, 'data.access_token') ?? '');
        if ($t === '') {
            throw new RemitException('WacePay : jeton absent de la réponse /login (' . (data_get($json, 'message') ?? 'vérifiez e-mail / mot de passe') . ')', $this->code($json), null, $json, true);
        }
        $ttl = min((int) $this->cfg('token_ttl_minutes', 55) * 60, max(60, (int) data_get($json, 'expires_in', 3600) - 120));
        Cache::put($key, $t, now()->addSeconds($ttl));
        return $t;
    }

    // ------------------------------------------------------------ Référentiels

    /** Services de versement d'un pays / devise : [{code, name, method}] (cache 6 h). */
    public function payoutServices(string $country, string $currency): array
    {
        $country = strtoupper($country);
        $currency = strtoupper($currency);
        return Cache::remember("wacepay_remit:services:{$country}:{$currency}", now()->addHours(6), function () use ($country, $currency) {
            $json = $this->send('post', 'transaction/payouts/services', ['payoutCountry' => $country, 'payoutCurrency' => $currency]);
            return collect((array) (data_get($json, 'data') ?? []))->filter(fn ($s) => is_array($s))->map(function ($s) {
                $svc = ['code' => (string) data_get($s, 'ServiceCode', ''), 'name' => trim((string) data_get($s, 'ServiceName', ''))];
                return $svc + ['method' => self::methodOf($svc)];
            })->filter(fn ($s) => $s['code'] !== '' || $s['name'] !== '')->values()->all();
        });
    }

    /** Méthode FlashPay (wallet | bank | cash) d'un service WacePay. */
    public static function methodOf(array $service): string
    {
        $n = strtoupper(($service['name'] ?? '') . ' ' . ($service['code'] ?? ''));
        return match (true) {
            str_contains($n, 'BANK') || ($service['code'] ?? '') === 'B' => 'bank',
            str_contains($n, 'CASH') || str_contains($n, 'PICK') => 'cash',
            default => 'wallet',
        };
    }

    /** PayerCode d'un service (essaie le code du service puis son libellé). Cache 24 h. */
    public function payerCode(string $country, string $currency, array $service): string
    {
        $country = strtoupper($country);
        $currency = strtoupper($currency);
        $key = "wacepay_remit:payer:{$country}:{$currency}:" . md5(($service['code'] ?? '') . '|' . ($service['name'] ?? ''));
        if ($c = Cache::get($key)) {
            return $c;
        }
        $last = null;
        foreach (array_values(array_unique(array_filter([$service['code'] ?? null, $service['name'] ?? null]))) as $try) {
            try {
                $json = $this->send('post', 'transaction/payercode', ['toCountry' => $country, 'payoutService' => $try, 'toCurrency' => $currency]);
                $code = (string) (data_get($json, 'transaction.PayerCode') ?? data_get($json, 'data.PayerCode') ?? '');
                if ($code !== '') {
                    Cache::put($key, $code, now()->addHours(24));
                    return $code;
                }
            } catch (RemitException $e) {
                $last = $e;
            }
        }
        throw $last ?? new RemitException('WacePay : aucun PayerCode pour ' . ($service['name'] ?? '?') . " ({$country}/{$currency}).", '2008', null, [], true);
    }

    /** Banques d'un pays : [{id, name, code}] */
    public function banks(string $country, string $payerCode): array
    {
        return Cache::remember('wacepay_remit:banks:' . strtoupper($country) . ':' . $payerCode, now()->addHours(12), function () use ($country, $payerCode) {
            $json = $this->send('post', 'transaction/bank/list', ['iso2' => strtoupper($country), 'payercode' => $payerCode]);
            return collect((array) (data_get($json, 'data') ?? []))->filter(fn ($b) => is_array($b))->map(fn ($b) => [
                'id' => (int) data_get($b, 'BankID'), 'name' => (string) data_get($b, 'BankName'), 'code' => (string) data_get($b, 'BankCode'),
            ])->filter(fn ($b) => $b['id'] > 0)->values()->all();
        });
    }

    /** Points de retrait cash : [{city, code, location, address}] */
    public function cashPoints(string $country, string $toCurrency, string $payerCode, string $fromCurrency = 'XAF'): array
    {
        return Cache::remember('wacepay_remit:cash:' . strtoupper($country) . ":{$toCurrency}:{$payerCode}", now()->addHours(12), function () use ($country, $toCurrency, $payerCode, $fromCurrency) {
            $json = $this->send('post', 'transaction/cash/collection_code_by_country', [
                'toCountry' => strtoupper($country), 'fromCurrency' => $fromCurrency, 'toCurrency' => strtoupper($toCurrency), 'payercode' => $payerCode,
            ]);
            return collect((array) (data_get($json, 'messages') ?? data_get($json, 'data') ?? []))->filter(fn ($p) => is_array($p))->map(fn ($p) => [
                'city' => (string) data_get($p, 'City'), 'code' => (string) (data_get($p, 'CollectionCode') ?? ''),
                'location' => (string) data_get($p, 'Location'), 'address' => (string) data_get($p, 'Address'),
            ])->values()->all();
        });
    }

    /** Listes relation / origine des fonds / motif (clé => libellé), par type d'affaire (p2p, p2b, b2p, b2b). */
    public function lists(string $businessType): array
    {
        $bt = strtolower($businessType);
        return Cache::remember("wacepay_remit:lists:{$bt}", now()->addHours(24), fn () => [
            'relations' => $this->assoc($this->send('get', 'transaction/relation')),
            'origin_funds' => $this->assoc($this->send('get', 'transaction/origin_fund/' . $bt)),
            'reasons' => $this->assoc($this->send('get', 'transaction/reason/' . $bt)),
        ]);
    }

    /** Solde de l'agence : {balance, currency} */
    public function balance(): array
    {
        $json = $this->send('get', 'account/balance');
        return ['balance' => (float) data_get($json, 'data.Balance', 0), 'currency' => (string) data_get($json, 'data.Currency', 'XAF'), 'raw' => $json];
    }

    // ------------------------------------------------------------ Expéditeur / bénéficiaire

    /** Corps de /sender/create (type P = particulier, B = entreprise). */
    public function senderBody(array $s): array
    {
        $type = ($s['type'] ?? 'P') === 'B' ? 'B' : 'P';
        return array_filter([
            'type' => $type,
            'firstName' => $s['first_name'] ?? null,
            'lastName' => $s['last_name'] ?? null,
            'businessName' => $type === 'B' ? ($s['business_name'] ?? null) : null,
            'businessType' => $type === 'B' ? ($s['business_type'] ?? 'SARL') : null,
            'email' => $s['email'] ?? null,
            'address' => $s['address'] ?? $this->cfg('default_address'),
            'phone' => $s['phone'] ?? null,
            'country' => strtoupper((string) ($s['country'] ?? config('flashpay.remit.from_country', 'CG'))),
            'city' => $s['city'] ?? $this->cfg('default_address'),
            'gender' => $s['gender'] ?? 'M',
            // Valeurs WacePay : Single, Maried (orthographe de l'API), Others
            'civility' => match ($s['civility'] ?? $this->cfg('default_civility', 'Single')) { 'Married', 'Maried' => 'Maried', 'Single' => 'Single', default => 'Others' },
            'idNumber' => $type === 'B' ? ($s['registration_number'] ?? null) : ($s['id_number'] ?? null),
            'idType' => $type === 'B' ? 'RCCM' : ($s['id_type'] ?? 'CNI'),
            'occupation' => $s['occupation'] ?? null,
            'nationality' => strtoupper((string) ($s['nationality'] ?? $s['country'] ?? 'CG')),
            'comment' => $type === 'B' ? ($s['business_description'] ?? $s['business_name'] ?? null) : null,
            'dateOfBirth' => $s['date_of_birth'] ?? null,
            'regiterBusinessDate' => $type === 'B' ? ($s['registered_at'] ?? null) : null,
            'dateExpireId' => $s['id_expiry'] ?? null,
            'pep' => (int) (bool) ($s['pep'] ?? false),
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function beneficiaryBody(array $b, string $senderCode): array
    {
        $type = ($b['type'] ?? 'P') === 'B' ? 'B' : 'P';
        $phone = (string) ($b['phone'] ?? '');
        return array_filter([
            'type' => $type,
            'firstName' => $type === 'P' ? ($b['first_name'] ?? null) : ($b['business_name'] ?? null),
            'lastName' => $type === 'P' ? ($b['last_name'] ?? null) : ($b['business_type'] ?? 'SARL'),
            'businessName' => $type === 'B' ? ($b['business_name'] ?? null) : null,
            'businessType' => $type === 'B' ? ($b['business_type'] ?? 'SARL') : null,
            'address' => $b['address'] ?? $b['city'] ?? null,
            'phone' => $phone,
            'mobile' => ltrim($phone, '+'),
            'country' => strtoupper((string) $b['country']),
            'city' => $b['city'] ?? null,
            'email' => $b['email'] ?? null,
            'idNumber' => $type === 'B' ? ($b['registration_number'] ?? null) : ($b['id_number'] ?? null),
            'idType' => $type === 'B' ? 'RCCM' : ($b['id_type'] ?? null),
            'dob' => $type === 'B' ? ($b['registered_at'] ?? null) : ($b['date_of_birth'] ?? null),
            'expire_date' => $type === 'B' ? ($b['registration_expiry'] ?? null) : null,
            'sender_code' => $senderCode,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Code expéditeur (créé une fois, réutilisé tant que les données d'identité ne changent pas). */
    public function senderCode(array $s, ?int $userId = null): string
    {
        $body = $this->senderBody($s);
        return $this->party('sender', $body, $userId, function () use ($body) {
            $json = $this->send('post', 'sender/create', $body);
            return (string) (data_get($json, 'sender.Code') ?? data_get($json, 'data.Code') ?? '');
        });
    }

    public function beneficiaryCode(array $b, string $senderCode, ?int $userId = null): string
    {
        $body = $this->beneficiaryBody($b, $senderCode);
        return $this->party('beneficiary', $body, $userId, function () use ($body) {
            $json = $this->send('post', 'beneficiary/create', $body);
            return (string) (data_get($json, 'beneficiary.Code') ?? data_get($json, 'data.Code') ?? '');
        });
    }

    protected function party(string $kind, array $body, ?int $userId, callable $create): string
    {
        $key = hash('sha256', json_encode($body) . '|' . $this->cfg('base_url'));
        if ($p = RemitParty::where(['partner' => 'wacepay_remit', 'kind' => $kind, 'key' => $key])->first()) {
            return $p->code;
        }
        $code = $create();
        if ($code === '') {
            throw new RemitException("WacePay : code {$kind} absent de la réponse.", '1003', null, [], true);
        }
        RemitParty::firstOrCreate(['partner' => 'wacepay_remit', 'kind' => $kind, 'key' => $key], ['code' => $code, 'user_id' => $userId]);
        return $code;
    }

    // ------------------------------------------------------------ Transactions

    /** @return array{reference:string, status:string, raw:array, sent:array, fees:?float} */
    public function createWallet(string $payerCode, int $amount, string $fromCurrency, string $senderCode, string $beneficiaryCode, string $msisdn, string $fromCountry, array $why): array
    {
        $body = [
            'payerCode' => $payerCode, 'amountToPaid' => (string) $amount, 'senderCode' => $senderCode, 'beneficiaryCode' => $beneficiaryCode,
            'fromCurrency' => $fromCurrency, 'mobileReceiveNumber' => ltrim($msisdn, '+'), 'fromCountry' => $fromCountry,
            'originFund' => $why['origin_fund'], 'reason' => $why['reason'], 'relation' => $why['relation'],
        ];
        return $this->created($this->send('post', 'transaction/wallet/create', $body), $body);
    }

    public function createBank(string $payerCode, int $amount, string $fromCurrency, string $senderCode, string $beneficiaryCode, array $bank, string $fromCountry, array $why, string $comment): array
    {
        $body = [
            'payerCode' => $payerCode, 'amountToPaid' => (string) $amount, 'senderCode' => $senderCode, 'beneficiaryCode' => $beneficiaryCode,
            'fromCountry' => $fromCountry, 'comment' => mb_substr($comment, 0, 160), 'bankAccount' => $bank['account'], 'bankName' => $bank['name'],
            'bankId' => (int) ($bank['id'] ?? 0), 'bankBranch' => $bank['branch'] ?? $bank['name'], 'bankSwCode' => $bank['swift'] ?? '',
            'originFund' => $why['origin_fund'], 'reason' => $why['reason'], 'relation' => $why['relation'], 'fromCurrency' => $fromCurrency,
        ];
        return $this->created($this->send('post', 'transaction/bank/create', $body), $body);
    }

    public function createCash(array $p, array $why): array
    {
        $body = [
            'payoutCollectionCode' => $p['collection_code'], 'toCurrency' => $p['to_currency'], 'toCity' => $p['to_city'], 'toCountry' => $p['to_country'],
            'amountToPaid' => (string) $p['amount'], 'service' => $p['service'], 'question' => $p['question'], 'response' => $p['answer'],
            'senderCode' => $p['sender_code'], 'beneficiaryCode' => $p['beneficiary_code'], 'fromCurrency' => $p['from_currency'],
            'mobileTopup' => $p['mobile'], 'fromCountry' => $p['from_country'],
            'originFund' => $why['origin_fund'], 'reason' => $why['reason'], 'relation' => $why['relation'],
        ];
        return $this->created($this->send('post', 'transaction/cash/create', $body), $body);
    }

    /** @return array{status:string, raw_status:?string, raw:array} */
    public function status(string $reference): array
    {
        $json = $this->send('get', 'transaction/status/' . rawurlencode($reference));
        $raw = (string) (data_get($json, 'transaction.Status') ?? data_get($json, 'transaction.status') ?? '');
        return ['status' => self::mapStatus($raw), 'raw_status' => $raw ?: null, 'raw' => $json];
    }

    public function confirm(string $reference): array
    {
        return $this->send('post', 'transaction/confirm', ['reference' => $reference]);
    }

    /** PAID → successful ; CANCEL → failed ; reste (PENDING, WAITING CONFIRMATION, PROCESSING, LOCKED) → pending. */
    public static function mapStatus(?string $raw): string
    {
        $s = strtoupper(trim((string) $raw));
        return match (true) {
            in_array($s, ['PAID', 'SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true) => 'successful',
            in_array($s, ['CANCEL', 'CANCELLED', 'CANCELED', 'FAILED', 'REJECTED'], true) => 'failed',
            default => 'pending',
        };
    }

    protected function created(array $json, array $sent): array
    {
        $ref = (string) (data_get($json, 'transaction.reference') ?? data_get($json, 'transaction.Reference') ?? '');
        if ($ref === '') {
            $m = data_get($json, 'message') ?? data_get($json, 'messages');
            throw new RemitException('WacePay : référence de transaction absente (' . (is_scalar($m) ? $m : 'réponse inattendue') . ')', null, null, $json);
        }
        $fees = data_get($json, 'transaction.feeds') ?? data_get($json, 'transaction.fees');
        return ['reference' => $ref, 'status' => 'pending', 'raw' => $json, 'sent' => $sent, 'fees' => is_numeric($fees) ? (float) $fees : null];
    }

    protected function assoc(array $json): array
    {
        $data = data_get($json, 'data', $json);
        return collect((array) $data)->filter(fn ($v, $k) => is_string($k) && is_scalar($v) && ! in_array($k, ['status', 'stauts', 'message', 'messages'], true))
            ->map(fn ($v) => (string) $v)->all();
    }

    protected function code(array $json): ?string
    {
        foreach (['status', 'stauts', 'code', 'statusCode'] as $k) {
            $c = data_get($json, $k);
            if (is_scalar($c) && preg_match('/^\d{3,4}$/', (string) $c)) {
                return (string) $c;
            }
        }
        return null;
    }

    public function send(string $method, string $path, array $body = [], bool $auth = true, bool $retried = false): array
    {
        if (! $this->enabled()) {
            throw new RemitException('WacePay remittance non configuré (WACEPAY_REMIT_ENABLED, WACEPAY_REMIT_EMAIL, WACEPAY_REMIT_PASSWORD).', null, null, [], true);
        }
        $url = rtrim((string) $this->cfg('base_url'), '/') . '/' . ltrim($path, '/');
        $req = Http::acceptJson()->asJson()->timeout((int) $this->cfg('timeout', 40));
        if ($auth) {
            $req = $req->withToken($this->token());
        }
        try {
            $res = $method === 'get' ? $req->get($url) : $req->post($url, $body);
        } catch (ConnectionException $e) {
            throw new RemitException('WacePay injoignable : ' . $e->getMessage());
        }
        $json = (array) ($res->json() ?? []);
        $code = $this->code($json);

        // Jeton expiré : on en redemande un, une seule fois
        if ($auth && ! $retried && ($res->status() === 401 || $code === '1002')) {
            $this->token(fresh: true);
            return $this->send($method, $path, $body, $auth, true);
        }
        // Succès : HTTP 2xx et code 200…206 / 2000 (203 annulée / 206 bloquée : statut lu dans transaction.Status) (ou pas de code métier)
        $okCode = $code === null || in_array($code, ['200', '201', '202', '203', '204', '205', '206', '2000'], true);
        if ($res->successful() && $okCode) {
            return $json;
        }
        $m = data_get($json, 'message') ?? data_get($json, 'messages') ?? $res->reason();
        $msg = is_scalar($m) ? (string) $m : json_encode($m, JSON_UNESCAPED_UNICODE);
        if (is_array($e = data_get($json, 'errors'))) {
            $msg .= ' — ' . collect($e)->flatten()->filter(fn ($v) => is_scalar($v))->implode(' ');
        }
        Log::warning('WacePay remittance : requête refusée', ['path' => $path, 'http' => $res->status(), 'code' => $code]);
        throw new RemitException('WacePay' . ($code ? " ({$code})" : '') . ' : ' . trim($msg), $code, $res->status(), $json);
    }
}
