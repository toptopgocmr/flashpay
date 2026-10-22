<?php

namespace App\Services\Remit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * pawaPay — API v2 (https://docs.pawapay.io/v2).
 *
 *   Authorization: Bearer <jeton API>
 *   POST /v2/predict-provider        { phoneNumber } → { country (ISO3), provider, phoneNumber }
 *   POST /v2/remittances             transfert international (KYC expéditeur + détails de l'opération)
 *   POST /v2/payouts                 versement simple (mode PAWAPAY_MODE=payout, et versements nationaux)
 *   POST /v2/deposits                collecte mobile money (le client valide sur son téléphone)
 *   GET  /v2/deposits|payouts/{id}   statut d'une collecte / d'un versement
 *   GET  /v2/remittances/{id}        { status: FOUND|NOT_FOUND, data: { status: ACCEPTED|ENQUEUED|PROCESSING|IN_RECONCILIATION|COMPLETED|FAILED } }
 *   GET  /v2/active-conf             pays / opérateurs / opérations ouvertes
 *   GET  /v2/wallet-balances         soldes par pays
 * Réponse à l'envoi : ACCEPTED | DUPLICATE_IGNORED | REJECTED (failureReason).
 * Réception : mobile money uniquement.
 */
class PawapayClient
{
    /** ISO2 → ISO3 des pays pawaPay. */
    public const ISO3 = [
        'BJ' => 'BEN', 'BF' => 'BFA', 'CM' => 'CMR', 'CI' => 'CIV', 'CD' => 'COD', 'CG' => 'COG', 'GA' => 'GAB', 'GH' => 'GHA',
        'KE' => 'KEN', 'LS' => 'LSO', 'MW' => 'MWI', 'MZ' => 'MOZ', 'NG' => 'NGA', 'RW' => 'RWA', 'SN' => 'SEN', 'SL' => 'SLE',
        'TZ' => 'TZA', 'UG' => 'UGA', 'ZM' => 'ZMB', 'ZW' => 'ZWE', 'ET' => 'ETH', 'TG' => 'TGO', 'ML' => 'MLI', 'NE' => 'NER',
        'GN' => 'GIN', 'TD' => 'TCD', 'CF' => 'CAF', 'GQ' => 'GNQ', 'FR' => 'FRA', 'US' => 'USA', 'BE' => 'BEL', 'GB' => 'GBR',
    ];

    protected function cfg(string $k, $d = null)
    {
        return config('flashpay.pawapay.' . $k, $d);
    }

    public function enabled(): bool
    {
        return (bool) $this->cfg('enabled') && (bool) $this->cfg('token');
    }

    public function sandbox(): bool
    {
        return str_contains((string) $this->cfg('base_url'), 'sandbox');
    }

    public static function iso3(?string $iso2): ?string
    {
        return $iso2 ? (self::ISO3[strtoupper($iso2)] ?? null) : null;
    }

    /** Opérateur du numéro (vérifie aussi que le numéro est valide). @return array{country:?string, provider:string, phone:string} */
    public function predictProvider(string $msisdn): array
    {
        $json = $this->send('post', 'v2/predict-provider', ['phoneNumber' => preg_replace('/\D/', '', $msisdn)]);
        $provider = (string) data_get($json, 'provider', '');
        if ($provider === '') {
            throw new RemitException('pawaPay : opérateur introuvable pour ce numéro.', 'INVALID_PHONE_NUMBER', null, $json, true);
        }
        return ['country' => data_get($json, 'country'), 'provider' => $provider, 'phone' => (string) data_get($json, 'phoneNumber', preg_replace('/\D/', '', $msisdn))];
    }

    /** Pays (ISO3) où les versements / remittances sont ouverts : [ISO3 => [providers…]] (cache 1 h). */
    public function activeCountries(): array
    {
        $op = $this->mode() === 'payout' ? 'PAYOUT' : 'REMITTANCE';
        return Cache::remember("pawapay:active:{$op}", 3600, function () use ($op) {
            $json = $this->send('get', 'v2/active-conf', ['operationType' => $op]);
            $out = [];
            foreach ((array) data_get($json, 'countries', []) as $c) {
                $providers = collect((array) ($c['providers'] ?? []))->filter(function ($p) use ($op) {
                    foreach ((array) ($p['currencies'] ?? []) as $cur) {
                        $ops = (array) ($cur['operationTypes'] ?? []);
                        $o = $ops[$op] ?? collect($ops)->firstWhere('operationType', $op);
                        if ($o && ($o['status'] ?? 'OPERATIONAL') !== 'CLOSED') {
                            return true;
                        }
                    }
                    return false;
                })->pluck('provider')->values()->all();
                if ($providers) {
                    $out[(string) $c['country']] = $providers;
                }
            }
            return $out;
        });
    }

    /**
     * Couverture réelle du compte pawaPay (GET /v2/active-conf) pour la passerelle nationale :
     * [ISO2 => ['collect' => bool, 'payout' => bool, 'providers' => [noms…]]]. Cache 10 min.
     */
    public function coverage(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('pawapay:coverage');
        }
        return Cache::remember('pawapay:coverage', 600, function () {
            $json = $this->send('get', 'v2/active-conf');
            $iso2 = array_flip(self::ISO3);
            $out = [];
            foreach ((array) data_get($json, 'countries', []) as $c) {
                $iso = $iso2[strtoupper((string) ($c['country'] ?? ''))] ?? null;
                if (! $iso) {
                    continue;
                }
                $row = $out[$iso] ?? ['collect' => false, 'payout' => false, 'providers' => []];
                foreach ((array) ($c['providers'] ?? []) as $p) {
                    $any = false;
                    foreach ((array) ($p['currencies'] ?? []) as $cur) {
                        foreach (['DEPOSIT' => 'collect', 'PAYOUT' => 'payout'] as $op => $flow) {
                            $ops = (array) ($cur['operationTypes'] ?? []);
                            $o = $ops[$op] ?? collect($ops)->firstWhere('operationType', $op);
                            if ($o && strtoupper((string) ($o['status'] ?? 'OPERATIONAL')) !== 'CLOSED') {
                                $row[$flow] = true;
                                $any = true;
                            }
                        }
                    }
                    if ($any) {
                        $row['providers'][] = (string) ($p['displayName'] ?? $p['provider'] ?? '');
                    }
                }
                $row['providers'] = array_values(array_unique(array_filter($row['providers'])));
                $out[$iso] = $row;
            }
            Cache::put('pawapay:coverage:synced_at', now()->toIso8601String(), 86400);
            return $out;
        });
    }

    public function mode(): string
    {
        return $this->cfg('mode', 'remittance') === 'payout' ? 'payout' : 'remittance';
    }

    /**
     * Envoi (remittance ou payout selon PAWAPAY_MODE).
     * @return array{reference:string, status:string, raw_status:?string, raw:array, sent:array}
     */
    public function transfer(string $id, array $p): array
    {
        $amount = number_format((float) $p['amount'], $p['decimals'] ?? 0, '.', '');
        $recipient = ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => $p['phone'], 'provider' => $p['provider']]];
        $message = self::customerMessage((string) ($p['message'] ?? 'OfficePay'));

        if ($this->mode() === 'payout') {
            $body = ['payoutId' => $id, 'recipient' => $recipient, 'amount' => $amount, 'currency' => $p['currency'],
                'clientReferenceId' => $p['reference'], 'customerMessage' => $message];
            $json = $this->send('post', 'v2/payouts', $body);
        } else {
            $recipient['recipientDetails'] = ['firstName' => $p['recipient_first_name'], 'lastName' => $p['recipient_last_name']];
            $s = $p['sender'];
            $body = [
                'remittanceId' => $id, 'amount' => $amount, 'currency' => $p['currency'], 'recipient' => $recipient,
                'customerMessage' => $message,
                'sender' => [
                    'transactionDetails' => [
                        'transactionReference' => $p['reference'],
                        'originalAmount' => (string) $p['original_amount'],
                        'originalCurrency' => $p['original_currency'],
                        'buyFxRate' => (string) $p['fx_rate'],
                        'senderFees' => (string) $p['sender_fees'],
                        'purposeOfFunds' => $p['purpose'],
                        'sourceOfFunds' => $p['source_of_funds'],
                    ],
                    'senderDetails' => array_filter([
                        'firstName' => $s['first_name'], 'lastName' => $s['last_name'],
                        'nationality' => $s['nationality'], 'phoneNumber' => $s['phone'],
                        'address' => array_filter(['addressLine' => $s['address'], 'postalCode' => ($s['postal_code'] ?? null) ?: '00000', 'city' => $s['city'], 'country' => $s['country']]),
                        'identification' => ['type' => $s['id_type'], 'number' => $s['id_number']],
                        'dateOfBirth' => $s['date_of_birth'] ?? null,
                        'gender' => $s['gender'] ?? null,
                        'occupation' => $s['occupation'] ?? null,
                        'relationshipRecipient' => $s['relationship'] ?? null,
                    ], fn ($v) => $v !== null && $v !== ''),
                ],
            ];
            $json = $this->send('post', 'v2/remittances', $body);
        }

        $status = strtoupper((string) data_get($json, 'status', ''));
        if ($status === 'REJECTED') {
            $code = (string) data_get($json, 'failureReason.failureCode', 'REJECTED');
            throw new RemitException('pawaPay : ' . (data_get($json, 'failureReason.failureMessage') ?: $code), $code, null, $json, true);
        }
        return ['reference' => $id, 'status' => 'pending', 'raw_status' => $status ?: null, 'raw' => $json, 'sent' => $body];
    }

    /** @return array{status:string, raw_status:?string, raw:array} */
    public function status(string $id): array
    {
        return $this->operationStatus($this->mode() === 'payout' ? 'payouts' : 'remittances', $id);
    }

    /** Opérateur du numéro, gardé 10 min (évite un appel par devis). */
    public function providerFor(string $msisdn): array
    {
        $digits = preg_replace('/\D/', '', $msisdn);
        return Cache::remember('pawapay:provider:' . $digits, 600, fn () => $this->predictProvider($digits));
    }

    /**
     * Collecte (deposit) ou versement national (payout) mobile money.
     * @param 'deposits'|'payouts' $kind
     * @param array{phone:string, provider:string, amount:int|float, currency:string, reference:string, message?:string, decimals?:int} $p
     * @return array{reference:string, status:string, raw_status:?string, raw:array, sent:array}
     */
    public function initiate(string $kind, string $id, array $p): array
    {
        $party = ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => preg_replace('/\D/', '', $p['phone']), 'provider' => $p['provider']]];
        $body = [
            $kind === 'deposits' ? 'depositId' : 'payoutId' => $id,
            $kind === 'deposits' ? 'payer' : 'recipient' => $party,
            'amount' => number_format((float) $p['amount'], $p['decimals'] ?? 0, '.', ''),
            'currency' => strtoupper($p['currency']),
            'clientReferenceId' => $p['reference'],
            'customerMessage' => self::customerMessage((string) ($p['message'] ?? 'OfficePay')),
        ];
        $json = $this->send('post', 'v2/' . $kind, $body);
        $status = strtoupper((string) data_get($json, 'status', ''));
        if ($status === 'REJECTED') {
            $code = (string) data_get($json, 'failureReason.failureCode', 'REJECTED');
            throw new RemitException('pawaPay : ' . (data_get($json, 'failureReason.failureMessage') ?: $code), $code, null, $json, true);
        }
        return ['reference' => $id, 'status' => 'pending', 'raw_status' => $status ?: null, 'raw' => $json, 'sent' => $body];
    }

    /** @param 'deposits'|'payouts'|'remittances' $kind @return array{status:string, raw_status:?string, raw:array, failure:?string} */
    public function operationStatus(string $kind, string $id): array
    {
        $json = $this->send('get', 'v2/' . $kind . '/' . rawurlencode($id));
        if (strtoupper((string) data_get($json, 'status')) === 'NOT_FOUND') {
            return ['status' => 'pending', 'raw_status' => 'NOT_FOUND', 'raw' => $json, 'failure' => null];
        }
        $raw = strtoupper((string) data_get($json, 'data.status', ''));
        $failure = data_get($json, 'data.failureReason.failureMessage') ?: data_get($json, 'data.failureReason.failureCode');
        return ['status' => self::mapStatus($raw), 'raw_status' => $raw ?: null, 'raw' => $json, 'failure' => $failure ? (string) $failure : null];
    }

    /** Solde disponible pour une devise (null = inconnu : on ne bloque pas). */
    public function availableFor(string $currency, ?string $iso2 = null): ?float
    {
        try {
            $list = Cache::remember('pawapay:balances', 60, fn () => $this->balances());
        } catch (\Throwable) {
            return null;
        }
        $iso3 = self::iso3($iso2);
        $rows = collect($list)->filter(fn ($b) => strtoupper((string) $b['currency']) === strtoupper($currency)
            && (! $iso3 || ! $b['country'] || strtoupper((string) $b['country']) === $iso3));
        return $rows->isEmpty() ? null : (float) $rows->sum('balance');
    }

    /** Soldes : [{country, currency, balance}] */
    public function balances(): array
    {
        $json = $this->send('get', (string) $this->cfg('balances_path', 'v2/wallet-balances'));
        return collect((array) (data_get($json, 'balances') ?? $json))->filter(fn ($b) => is_array($b))->map(fn ($b) => [
            'country' => $b['country'] ?? null, 'currency' => $b['currency'] ?? null, 'balance' => (float) ($b['balance'] ?? 0),
        ])->values()->all();
    }

    public static function mapStatus(?string $raw): string
    {
        return match (strtoupper(trim((string) $raw))) {
            'COMPLETED' => 'successful',
            'FAILED', 'REJECTED', 'CANCELLED' => 'failed',
            default => 'pending',
        };
    }

    /** 4 à 22 caractères : lettres, chiffres, espaces. */
    public static function customerMessage(string $m): string
    {
        $m = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $m) ?: $m)));
        $m = mb_substr($m, 0, 22);
        return strlen($m) >= 4 ? $m : 'OfficePay';
    }

    public function send(string $method, string $path, array $body = []): array
    {
        if (! $this->enabled()) {
            throw new RemitException('pawaPay non configuré (PAWAPAY_ENABLED, PAWAPAY_API_TOKEN).', null, null, [], true);
        }
        $url = rtrim((string) $this->cfg('base_url'), '/') . '/' . ltrim($path, '/');
        $req = Http::withToken((string) $this->cfg('token'))->acceptJson()->asJson()->timeout((int) $this->cfg('timeout', 40));
        try {
            $res = $method === 'get' ? $req->get($url, $body) : $req->post($url, $body);
        } catch (ConnectionException $e) {
            throw new RemitException('pawaPay injoignable : ' . $e->getMessage());
        }
        $json = (array) ($res->json() ?? []);
        if ($res->successful()) {
            return $json;
        }
        $code = (string) (data_get($json, 'failureReason.failureCode') ?? data_get($json, 'errorCode') ?? '');
        $msg = (string) (data_get($json, 'failureReason.failureMessage') ?? data_get($json, 'errorMessage') ?? data_get($json, 'message') ?? $res->reason());
        Log::warning('pawaPay : requête refusée', ['path' => $path, 'http' => $res->status(), 'code' => $code]);
        throw new RemitException('pawaPay' . ($code ? " ({$code})" : '') . ' : ' . $msg, $code ?: null, $res->status(), $json);
    }
}
