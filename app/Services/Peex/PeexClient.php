<?php

namespace App\Services\Peex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP bas niveau de l'API PEEX (https://peex-api-docs.peexit.com/).
 *
 * Trois familles d'API :
 *   - Collect       : collection/*    (débit d'un wallet mobile money)
 *   - Disbursement  : disbursement/*  (crédit d'un wallet mobile money)
 *   - Remittance    : clients/*       (transfert international vers mobile/banque)
 */
class PeexClient
{
    public function baseUrl(): string
    {
        $cfg = config('flashpay.peex');
        $url = $cfg['sandbox'] ? $cfg['sandbox_url'] : $cfg['production_url'];

        return rtrim($url, '/') . '/';
    }

    public function isSandbox(): bool
    {
        return (bool) config('flashpay.peex.sandbox');
    }

    protected function http(): PendingRequest
    {
        $key = config('flashpay.peex.secret_key');
        if (! $key) {
            throw new PeexException('PEEX_SECRET_KEY manquant dans .env');
        }

        return Http::baseUrl($this->baseUrl())
            ->timeout(config('flashpay.peex.timeout', 30))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['SECRETKEY' => $key]);
    }

    protected function call(string $method, string $path, array $data = []): array
    {
        try {
            $response = $method === 'GET'
                ? $this->http()->get($path, $data)
                : $this->http()->post($path, $data);
        } catch (ConnectionException $e) {
            Log::error('PEEX injoignable', ['path' => $path, 'error' => $e->getMessage()]);
            throw new PeexException('PEEX injoignable : ' . $e->getMessage());
        }

        $body = $response->json();
        if (! is_array($body)) {
            $body = ['raw' => $response->body()];
        }

        Log::channel(config('logging.default'))->info('PEEX ' . $method . ' ' . $path, [
            'status' => $response->status(),
            'request' => $data,
            'response' => $body,
        ]);

        if ($response->failed()) {
            $message = $body['message'] ?? $body['error'] ?? $body['errors'] ?? $response->reason();
            if (is_array($message)) {
                $message = json_encode($message, JSON_UNESCAPED_UNICODE);
            }
            throw new PeexException("PEEX {$response->status()} : {$message}", $response->status(), $body);
        }

        return $body;
    }

    // ---------------------------------------------------------------- Collect

    public function collectMe(): array
    {
        return $this->call('GET', 'collection/me');
    }

    /** @param array{amount:float|int,country:string,phone_number:string} $query */
    public function collectFees(array $query): array
    {
        return $this->call('GET', 'collection/get_fees', $query);
    }

    /**
     * @param array{track_id:string,phone:string,amount:int|float,currency:string,
     *              customer_name:string,country:string,description:string} $payload
     */
    public function collect(array $payload): array
    {
        return $this->call('POST', 'collection/request_payment', $payload);
    }

    public function collectStatus(string $trackId): array
    {
        return $this->call('GET', 'collection/all_requests', ['track_id' => $trackId]);
    }

    // ----------------------------------------------------------- Disbursement

    public function disbursementMe(): array
    {
        return $this->call('GET', 'disbursement/me');
    }

    public function disburse(array $payload): array
    {
        return $this->call('POST', 'disbursement/request_payment', $payload);
    }

    public function disbursementStatus(string $trackId): array
    {
        return $this->call('GET', 'disbursement/all_requests', ['track_id' => $trackId]);
    }

    // ------------------------------------------------------------- Remittance

    public function remittanceMe(): array
    {
        return $this->call('GET', 'clients/me');
    }

    /**
     * Vérification du compte mobile money + titulaire (Verify Wallet / Get KYC).
     * Réponse normalisée : {valid, name, status, operator, raw}.
     * 404 = compte inexistant chez l'opérateur (valid=false).
     */
    public function verifyWallet(string $countryIso, string $localNumber): array
    {
        try {
            $r = $this->call('POST', config('flashpay.peex.verify_wallet_path', 'clients/verify_wallet'), [
                'countryCode' => strtoupper($countryIso),
                'accountNumber' => $localNumber,
            ]);
        } catch (PeexException $e) {
            if ($e->httpStatus === 404) {
                return ['valid' => false, 'name' => null, 'status' => 'NOT_FOUND', 'operator' => null, 'raw' => $e->body];
            }
            throw $e;
        }

        $valid = $r['isValid'] ?? $r['valid'] ?? null;
        $status = $r['status'] ?? $r['accountStatus'] ?? null;
        $valid ??= ($r['accountName'] ?? $r['accountTitle'] ?? null) !== null;
        if (is_string($status) && ! in_array(strtoupper($status), ['ACTIVE', 'ACTIF'], true)) {
            $valid = false;
        }

        return [
            'valid' => (bool) $valid,
            'name' => $r['accountName'] ?? $r['accountTitle'] ?? null,
            'status' => $status,
            'operator' => $r['operator'] ?? null,
            'raw' => $r,
        ];
    }

    public function verifyPhone(string $internationalPhone): array
    {
        return $this->call('POST', 'clients/verify_phoneNumber', ['mobile_phone' => $internationalPhone]);
    }

    public function remit(array $payload): array
    {
        return $this->call('POST', 'clients/request_payment', $payload);
    }

    public function remittanceStatus(string $trackId): array
    {
        return $this->call('GET', 'clients/all_requests', ['track_id' => $trackId]);
    }

    // ---------------------------------------------------------------- Helpers

    public function statusFor(string $service, string $trackId): array
    {
        try {
            $list = $this->statusList($service, $trackId);
        } catch (PeexException $e) {
            if ($e->httpStatus === 404) {
                return []; // inconnue chez PEEX (jamais reçue, ou plus de 3 jours)
            }
            throw $e;
        }

        return self::extractRequest($list, $trackId) ?? [];
    }

    protected function statusList(string $service, string $trackId): array
    {
        return match ($service) {
            'collect' => $this->collectStatus($trackId),
            'disbursement' => $this->disbursementStatus($trackId),
            'remittance' => $this->remittanceStatus($trackId),
            default => throw new PeexException("Service PEEX inconnu : {$service}"),
        };
    }

    /**
     * Les réponses PEEX varient : {"request":{...}}, {"data":{...}}, [{...}], {...}.
     * Renvoie l'objet transaction correspondant au track_id (ou le premier).
     */
    public static function extractRequest(array $body, ?string $trackId = null): ?array
    {
        if (isset($body['request']) && is_array($body['request'])) {
            return $body['request'];
        }
        if (isset($body['data']) && is_array($body['data'])) {
            $body = $body['data'];
        }
        if (array_is_list($body)) {
            foreach ($body as $item) {
                if (is_array($item) && (! $trackId || ($item['track_id'] ?? null) === $trackId)) {
                    return $item;
                }
            }
            return null;
        }

        return isset($body['status']) || isset($body['track_id']) ? $body : null;
    }
}
