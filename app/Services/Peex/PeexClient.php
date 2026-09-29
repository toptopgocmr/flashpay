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
     *
     * Réponse normalisée : {valid, name, status, operator, raw} où valid vaut
     *   true  : PEEX confirme un compte actif ;
     *   false : PEEX affirme que le compte n'existe pas / n'est pas actif ;
     *   null  : PEEX n'a PAS tranché (route absente, pays non couvert par la
     *           vérification, numéro hors liste de test sandbox, réponse
     *           illisible…). Ce n'est pas un refus : l'appelant décide.
     *
     * La doc PEEX cite deux chemins (verify-wallet et verify_wallet) : si le
     * chemin configuré n'existe pas chez PEEX, on essaie l'autre et on retient
     * celui qui répond.
     */
    public function verifyWallet(string $countryIso, string $localNumber): array
    {
        $configured = trim((string) config('flashpay.peex.verify_wallet_path', 'clients/verify_wallet'), '/');
        $paths = array_values(array_unique(array_filter([
            \Illuminate\Support\Facades\Cache::get('peex:verify_wallet_path'),
            $configured,
            'clients/verify_wallet',
            'clients/verify-wallet',
        ])));

        $payload = ['countryCode' => strtoupper($countryIso), 'accountNumber' => $localNumber];
        $last = null;

        foreach ($paths as $path) {
            try {
                $r = $this->call('POST', $path, $payload);
            } catch (PeexException $e) {
                $last = $e;
                if ($e->httpStatus === 404 && self::isRouteMissing($e->body)) {
                    continue; // mauvais chemin : on essaie le suivant
                }
                if ($e->httpStatus === 404) {
                    \Illuminate\Support\Facades\Cache::put('peex:verify_wallet_path', $path, now()->addDay());
                    return ['valid' => false, 'name' => null, 'status' => 'NOT_FOUND', 'operator' => null, 'raw' => $e->body];
                }
                if (in_array($e->httpStatus, [400, 422], true)) {
                    // Paramètres refusés, pays non couvert, numéro hors sandbox… : pas un verdict sur le compte.
                    return ['valid' => null, 'name' => null, 'status' => 'UNVERIFIABLE', 'operator' => null, 'raw' => $e->body];
                }
                throw $e;
            }

            \Illuminate\Support\Facades\Cache::put('peex:verify_wallet_path', $path, now()->addDay());
            $r = is_array($r['data'] ?? null) ? $r['data'] : $r;

            $valid = $r['isValid'] ?? $r['valid'] ?? null;
            $status = $r['status'] ?? $r['accountStatus'] ?? null;
            $name = $r['accountName'] ?? $r['accountTitle'] ?? $r['name'] ?? null;
            if (is_string($valid)) {
                $valid = filter_var($valid, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            // Ex. production PEEX : {"valid":false,"message":"Unsupported account code: CG"}
            // = la vérification n'existe pas pour ce pays, ce n'est PAS un verdict sur le compte.
            $msg = strtolower((string) ($r['message'] ?? $r['error'] ?? ''));
            if ($valid === false && preg_match('/unsupported|not supported|non support|not available|not implemented/', $msg)) {
                return ['valid' => null, 'name' => null, 'status' => 'UNSUPPORTED', 'operator' => null, 'raw' => $r];
            }
            if ($valid === null && $name) {
                $valid = true;
            }
            if (is_string($status) && $status !== '') {
                $st = strtoupper($status);
                if (str_contains($st, 'INACTIV') || in_array($st, ['BLOCKED', 'SUSPENDED', 'CLOSED', 'NOT_FOUND', 'DISABLED'], true)) {
                    $valid = false;
                } elseif ($valid === null && (str_contains($st, 'ACTIV') || $st === 'OK')) {
                    $valid = true;
                }
            }

            return [
                'valid' => $valid === null ? null : (bool) $valid,
                'name' => $name ?: null,
                'status' => $status,
                'operator' => $r['operator'] ?? null,
                'raw' => $r,
            ];
        }

        // Aucun chemin ne répond : la vérification n'existe pas sur ce compte PEEX.
        Log::warning('PEEX verify_wallet introuvable sur tous les chemins', ['tried' => $paths, 'error' => $last?->getMessage()]);
        return ['valid' => null, 'name' => null, 'status' => 'UNSUPPORTED', 'operator' => null, 'raw' => $last?->body ?? []];
    }

    /** 404 « route inconnue » (LoopBack) et non « compte introuvable ». */
    public static function isRouteMissing(array $body): bool
    {
        $err = $body['error'] ?? $body;
        $msg = strtolower(is_array($err) ? json_encode($err) : (string) $err);
        if (str_contains($msg, 'account') || str_contains($msg, 'wallet not') || str_contains($msg, 'compte')) {
            return false;
        }
        return str_contains($msg, 'endpoint') || str_contains($msg, 'no method') || str_contains($msg, 'cannot post')
            || str_contains($msg, 'shared class') || str_contains($msg, 'route') || $msg === '' || $msg === '[]';
    }

    /**
     * La doc cite « mobile_phone » mais le sandbox exige « phone_number » :
     * on envoie les deux.
     */
    public function verifyPhone(string $internationalPhone): array
    {
        return $this->call('POST', 'clients/verify_phoneNumber', [
            'phone_number' => $internationalPhone,
            'mobile_phone' => $internationalPhone,
        ]);
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
