<?php

namespace App\Services\Remit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TerraPay — API GSMA (https://developers.terrapay.com/docs/).
 *
 * En-têtes : X-USERNAME, X-PASSWORD (SHA-256), X-DATE (Y-m-d H:i:s), X-ORIGINCOUNTRY (ISO2).
 *
 *   GET  /eig/gsma/accounts/msisdn/{msisdn}/status?bnv=…        compte wallet (available | unavailable | unregistered)
 *   GET  /eig/gsma/accounts/{accountId}/status?bnv=&bankname=&country=&bankcode=   compte bancaire
 *   POST /eig/gsma/quotations                                    devis (quoteId, fxRate, receivingAmount)
 *   POST /eig/gsma/transactions                                  envoi → transactionReference (TPKM…), « 3050:Remit Acknowledged. »
 *   GET  /eig/gsma/transactions/{ref}                            statut (chemin configurable : TERRAPAY_STATUS_PATH)
 */
class TerrapayClient
{
    protected function cfg(string $k, $d = null)
    {
        return config('flashpay.terrapay.' . $k, $d);
    }

    public function enabled(): bool
    {
        return (bool) $this->cfg('enabled') && $this->cfg('username') && $this->cfg('password');
    }

    public function sandbox(): bool
    {
        return str_contains((string) $this->cfg('base_url'), 'uat');
    }

    /** Statut du compte du bénéficiaire. @return array{available:bool, status:string, sub_status:?string, raw:array} */
    public function accountStatus(array $credit, string $beneficiaryName, ?string $senderName = null): array
    {
        if (($credit['method'] ?? 'wallet') === 'bank') {
            $path = 'eig/gsma/accounts/' . rawurlencode((string) $credit['account']) . '/status';
            $q = array_filter([
                'bnv' => mb_substr($beneficiaryName, 0, 50), 'bankname' => $credit['bank_name'] ?? null, 'country' => strtoupper((string) $credit['country']),
                'bankcode' => $credit['swift'] ?? null, 'provider' => $credit['bank_code'] ?? null, 'banksubcode' => $credit['branch_code'] ?? null,
                'accounttype' => $credit['account_type'] ?? null, 'snv' => $senderName,
            ], fn ($v) => $v !== null && $v !== '');
        } else {
            $path = 'eig/gsma/accounts/msisdn/' . rawurlencode((string) $credit['msisdn']) . '/status';
            $q = array_filter(['bnv' => mb_substr($beneficiaryName, 0, 50), 'provider' => $credit['provider'] ?? null, 'snv' => $senderName], fn ($v) => $v !== null && $v !== '');
        }
        $json = $this->send('get', $path, $q);
        $status = strtolower((string) data_get($json, 'status', ''));
        return ['available' => $status === 'available', 'status' => $status, 'sub_status' => data_get($json, 'subStatus'), 'raw' => $json];
    }

    /**
     * Devis « inverse » : le bénéficiaire reçoit exactement $receiveAmount ($receiveCurrency).
     * @return array{quote_id:string, sending_amount:float, sending_currency:string, receiving_amount:float, receiving_currency:string, fx_rate:float, expires_at:?string, raw:array}
     */
    public function quote(string $senderMsisdn, array $credit, float $amount, string $requestCurrency, string $sendingCurrency, string $receivingCurrency): array
    {
        $body = [
            'requestDate' => now()->format('Y-m-d H:i:s'),
            'debitParty' => [['key' => 'msisdn', 'value' => $senderMsisdn]],
            'creditParty' => $this->creditParty($credit, forQuote: true),
            'requestAmount' => number_format($amount, 2, '.', ''),
            'requestCurrency' => strtoupper($requestCurrency),
            'quotes' => [['sendingCurrency' => strtoupper($sendingCurrency), 'receivingCurrency' => strtoupper($receivingCurrency)]],
        ];
        $json = $this->send('post', 'eig/gsma/quotations', $body);
        $q = (array) data_get($json, 'quotes.0', []);
        $status = (string) data_get($json, 'quotationStatus', '');
        if (! $q || ! str_starts_with($status, '2000')) {
            throw new RemitException('TerraPay : devis refusé (' . ($status ?: 'réponse inattendue') . ')', substr($status, 0, 4) ?: null, null, $json, true);
        }
        return [
            'quote_id' => (string) ($q['quoteId'] ?? data_get($json, 'quotationReference')),
            'sending_amount' => (float) ($q['sendingAmount'] ?? 0), 'sending_currency' => (string) ($q['sendingCurrency'] ?? $sendingCurrency),
            'receiving_amount' => (float) ($q['receivingAmount'] ?? 0), 'receiving_currency' => (string) ($q['receivingCurrency'] ?? $receivingCurrency),
            'fx_rate' => (float) ($q['fxRate'] ?? 0), 'expires_at' => $q['quoteExpiryTime'] ?? null, 'raw' => $json,
        ];
    }

    /** @return array{reference:string, status:string, raw_status:?string, raw:array, sent:array} */
    public function transaction(array $p): array
    {
        $body = array_filter([
            'amount' => number_format((float) $p['amount'], 2, '.', ''),
            'currency' => strtoupper($p['currency']),
            'type' => $p['type'] ?? 'inttransfer',
            'descriptionText' => mb_substr((string) ($p['description'] ?? 'FlashPay'), 0, 100),
            'requestDate' => now()->format('Y-m-d H:i:s'),
            'requestingOrganisationTransactionReference' => $p['reference'],
            'provider' => ($p['credit']['method'] ?? 'wallet') === 'wallet' ? ($p['credit']['provider'] ?? null) : null,
            'debitParty' => [['key' => 'msisdn', 'value' => $p['sender_msisdn']]],
            'creditParty' => $this->creditParty($p['credit']),
            'senderKyc' => $p['sender_kyc'],
            'recipientKyc' => $p['recipient_kyc'],
            'internationalTransferInformation' => [
                'quoteId' => $p['quote_id'],
                'receivingCountry' => strtoupper($p['receiving_country']),
                'remittancePurpose' => $p['purpose'],
                'sourceOfFunds' => $p['source_of_funds'],
                'relationshipSender' => $p['relation'],
            ],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        $json = $this->send('post', 'eig/gsma/transactions', $body);
        $raw = (string) data_get($json, 'transactionStatus', '');
        $ref = (string) data_get($json, 'transactionReference', '');
        if ($ref === '') {
            throw new RemitException('TerraPay : référence de transaction absente (' . ($raw ?: 'réponse inattendue') . ')', substr($raw, 0, 4) ?: null, null, $json, self::mapStatus($raw) === 'failed');
        }
        return ['reference' => $ref, 'status' => self::mapStatus($raw, initial: true), 'raw_status' => $raw ?: null, 'raw' => $json, 'sent' => $body];
    }

    /** @return array{status:string, raw_status:?string, raw:array} */
    public function status(string $reference): array
    {
        $path = str_replace('{ref}', rawurlencode($reference), (string) $this->cfg('status_path', 'eig/gsma/transactions/{ref}'));
        $json = $this->send('get', $path);
        $raw = (string) (data_get($json, 'transactionStatus') ?? data_get($json, 'status') ?? '');
        return ['status' => self::mapStatus($raw), 'raw_status' => $raw ?: null, 'raw' => $json];
    }

    /**
     * « 3000:Remit Success » → successful ; « 3032:Remit Failed », annulé / rejeté / expiré → failed ;
     * « 3050:Remit Acknowledged. » et autres → pending.
     * $initial : un accusé de réception n'est jamais « failed » sans motif explicite.
     */
    public static function mapStatus(?string $raw, bool $initial = false): string
    {
        $s = strtolower(trim((string) $raw));
        $code = substr($s, 0, 4);
        return match (true) {
            $code === '3000' || (str_contains($s, 'success') && ! str_contains($s, 'acknowledged')) || str_contains($s, 'completed') || str_contains($s, 'paid') => 'successful',
            in_array($code, ['3032', '3031', '3033'], true) || str_contains($s, 'fail') || str_contains($s, 'cancel') || str_contains($s, 'reject') || str_contains($s, 'expire') => 'failed',
            default => 'pending',
        };
    }

    protected function creditParty(array $c, bool $forQuote = false): array
    {
        if (($c['method'] ?? 'wallet') === 'bank') {
            return array_values(array_filter([
                ['key' => 'bankaccountno', 'value' => (string) $c['account']],
                ['key' => 'receivingCountry', 'value' => strtoupper((string) $c['country'])],
                $forQuote ? null : (! empty($c['bank_name']) ? ['key' => 'organisationid', 'value' => (string) $c['bank_name']] : null),
                $forQuote ? null : (! empty($c['swift']) ? ['key' => 'sortcode', 'value' => (string) $c['swift']] : null),
                $forQuote ? null : (! empty($c['branch_code']) ? ['key' => 'banksubcode', 'value' => (string) $c['branch_code']] : null),
                $forQuote ? null : (! empty($c['account_type']) ? ['key' => 'accounttype', 'value' => (string) $c['account_type']] : null),
            ]));
        }
        return [['key' => 'msisdn', 'value' => (string) $c['msisdn']]];
    }

    protected function headers(): array
    {
        $pwd = (string) $this->cfg('password');
        return [
            'X-USERNAME' => (string) $this->cfg('username'),
            'X-PASSWORD' => $this->cfg('password_hashed') ? $pwd : hash('sha256', $pwd),
            'X-DATE' => now()->format('Y-m-d H:i:s'),
            'X-ORIGINCOUNTRY' => strtoupper((string) $this->cfg('origin_country', 'CG')),
        ];
    }

    public function send(string $method, string $path, array $body = []): array
    {
        if (! $this->enabled()) {
            throw new RemitException('TerraPay non configuré (TERRAPAY_ENABLED, TERRAPAY_USERNAME, TERRAPAY_PASSWORD).', null, null, [], true);
        }
        $url = rtrim((string) $this->cfg('base_url'), '/') . '/' . ltrim($path, '/');
        $req = Http::withHeaders($this->headers())->acceptJson()->asJson()->timeout((int) $this->cfg('timeout', 40));
        try {
            $res = $method === 'get' ? $req->get($url, $body) : $req->post($url, $body);
        } catch (ConnectionException $e) {
            throw new RemitException('TerraPay injoignable : ' . $e->getMessage());
        }
        $json = (array) ($res->json() ?? []);
        if ($res->successful() && ! data_get($json, 'error')) {
            return $json;
        }
        $code = (string) (data_get($json, 'error.errorCode') ?? '');
        $msg = (string) (data_get($json, 'error.errorDescription') ?? data_get($json, 'message') ?? $res->reason());
        Log::warning('TerraPay : requête refusée', ['path' => $path, 'http' => $res->status(), 'code' => $code]);
        throw new RemitException('TerraPay' . ($code ? " ({$code})" : '') . ' : ' . $msg, $code ?: null, $res->status(), $json);
    }
}
