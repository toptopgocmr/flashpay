<?php

namespace App\Services\NotchPay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client HTTP Notch Pay (https://developer.notchpay.co).
 *
 *   Authorization : clé publique (pk_…)        — toutes les requêtes
 *   X-Grant       : clé privée   (sk_…)        — transferts, solde (envoyée systématiquement côté serveur)
 *
 * Collecte « direct charge » : POST /payments (initialisation) puis POST /payments/{ref}
 * avec { channel: "cm.mtn", data: { phone } } → le client valide sur son téléphone.
 * Versement : POST /transfers { amount, currency, channel, beneficiary_data, reference }.
 * Statuts Notch Pay : pending | processing | complete | failed | canceled | expired.
 */
class NotchPayClient
{
    /** Codes opérateur FlashPay → fournisseur Notch Pay ({pays}.{fournisseur}). */
    protected const PROVIDERS = ['MTN' => 'mtn', 'ORANGE' => 'orange', 'WAVE' => 'wave', 'AIRTEL' => 'airtel',
        'MOOV' => 'moov', 'MPESA' => 'mpesa', 'FREE' => 'free', 'EXPRESSO' => 'expresso', 'CAMTEL' => 'camtel'];

    protected function cfg(string $key, $default = null)
    {
        return config('flashpay.notchpay.' . $key, $default);
    }

    public function enabled(): bool
    {
        return (bool) $this->cfg('enabled') && (bool) $this->cfg('public_key');
    }

    /** Mode test : clés pk_test.… / sk_test.… */
    public function sandbox(): bool
    {
        // pk_test.… (format actuel) ou pk_test_… : clé de test
        return (bool) preg_match('/^pk_test[._]/', (string) $this->cfg('public_key'));
    }

    public function callbackUrl(): string
    {
        return $this->cfg('callback_url') ?: rtrim((string) config('app.url'), '/') . '/api/webhooks/notchpay';
    }

    // ------------------------------------------------------------ Canaux

    /** Canal Notch Pay pour un pays + opérateur (ex. CM + « MTN MoMo » → cm.mtn). */
    public function channelFor(string $iso, ?string $operator): string
    {
        $iso = strtoupper($iso);
        $code = \App\Services\Digitwace\DigitwaceClient::operatorCode($operator);
        $map = (array) $this->cfg('channel_map', []);
        if ($code && isset($map["{$iso}:{$code}"])) {
            return $map["{$iso}:{$code}"];
        }
        if (isset($map[$iso])) {
            return $map[$iso];
        }
        if (! $code || ! isset(self::PROVIDERS[$code])) {
            throw new NotchPayException('Opérateur ' . ($operator ?: 'inconnu') . " non pris en charge par Notch Pay ({$iso}).", definitive: true);
        }
        $channel = strtolower($iso) . '.' . self::PROVIDERS[$code];
        $known = $this->knownChannels()[$iso] ?? null;
        if ($known && ! in_array($channel, $known, true)) {
            throw new NotchPayException("Canal {$channel} non proposé par Notch Pay (canaux disponibles pour {$iso} : " . implode(', ', $known) . ').', definitive: true);
        }
        return $channel;
    }

    /** Canaux synchronisés (GET /channels) : [ISO => [cm.mtn, cm.orange…]] ; vide = non synchronisé. */
    public function knownChannels(): array
    {
        return (array) Cache::get('notchpay:channels', []);
    }

    /** Synchronise la liste des canaux Notch Pay. */
    public function syncChannels(): array
    {
        $json = $this->send('get', 'channels');
        $out = [];
        $walk = function ($v) use (&$walk, &$out) {
            if (is_array($v)) {
                foreach ($v as $x) {
                    $walk($x);
                }
            } elseif (is_string($v) && preg_match('/^([a-z]{2})\.[a-z0-9_-]+$/', $v, $m)) {
                $out[strtoupper($m[1])][] = $v;
            }
        };
        $walk(data_get($json, 'items', $json));
        $out = array_map(fn ($l) => array_values(array_unique($l)), $out);
        ksort($out);
        if ($out) {
            Cache::forever('notchpay:channels', $out);
            Cache::forever('notchpay:channels:synced_at', now()->toIso8601String());
        }
        return $out;
    }

    /** Pays où Notch Pay peut être choisi dans la console. */
    public function countries(): array
    {
        return array_values(array_unique(array_merge((array) $this->cfg('countries', []), array_keys($this->knownChannels()))));
    }

    // ------------------------------------------------------------ Collecte (direct charge)

    /**
     * Initialise un paiement puis déclenche la demande de validation sur le téléphone du client.
     * @return array{notch_ref:string, status:string, raw_status:?string, raw:array, sent:array}
     */
    public function charge(string $reference, int $amount, string $currency, string $phone, string $channel, ?string $name = null, ?string $email = null, ?string $description = null): array
    {
        $init = [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'reference' => $reference,
            'description' => $description ?: 'FlashPay ' . $reference,
            'email' => $email ?: $this->cfg('default_email'),
            'phone' => $phone,
            'customer' => array_filter(['name' => $name, 'email' => $email ?: $this->cfg('default_email'), 'phone' => $phone]),
            'locked_channel' => $channel,
            'callback' => $this->callbackUrl(),
        ];
        $json = $this->send('post', 'payments', $init);
        $notchRef = (string) (data_get($json, 'transaction.reference') ?? (is_string(data_get($json, 'transaction')) ? data_get($json, 'transaction') : '') ?: $reference);

        $process = ['channel' => $channel, 'data' => ['phone' => $phone, 'account_number' => $phone]];
        $json2 = $this->send('post', 'payments/' . rawurlencode($notchRef), $process);
        $raw = (string) (data_get($json2, 'transaction.status') ?? data_get($json2, 'status') ?? '');

        return ['notch_ref' => $notchRef, 'status' => self::mapStatus($raw, true), 'raw_status' => $raw ?: null,
            'raw' => ['initialize' => $json, 'process' => $json2], 'sent' => ['initialize' => $init, 'process' => $process]];
    }

    public function payment(string $reference): array
    {
        $json = $this->send('get', 'payments/' . rawurlencode($reference));
        $raw = (string) (data_get($json, 'transaction.status') ?? '');
        return ['status' => self::mapStatus($raw), 'raw_status' => $raw ?: null, 'message' => data_get($json, 'transaction.failure_reason') ?? data_get($json, 'message'), 'raw' => $json];
    }

    // ------------------------------------------------------------ Versement (transfer)

    /** @return array{notch_ref:string, status:string, raw_status:?string, raw:array, sent:array} */
    public function transfer(string $reference, int $amount, string $currency, string $channel, string $phone, string $name, ?string $country = null, ?string $description = null): array
    {
        $body = [
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'channel' => $channel,
            'beneficiary_data' => array_filter(['name' => $name, 'phone' => $phone, 'country' => $country ? strtoupper($country) : null]),
            'description' => $description ?: 'FlashPay ' . $reference,
            'reference' => $reference,
        ];
        $json = $this->send('post', 'transfers', $body, grant: true);
        $raw = (string) (data_get($json, 'transfer.status') ?? '');

        return ['notch_ref' => (string) (data_get($json, 'transfer.id') ?? data_get($json, 'transfer.reference') ?? $reference),
            'status' => self::mapStatus($raw, true), 'raw_status' => $raw ?: null, 'raw' => $json, 'sent' => $body];
    }

    public function transferStatus(string $id): array
    {
        $json = $this->send('get', 'transfers/' . rawurlencode($id), grant: true);
        $raw = (string) (data_get($json, 'transfer.status') ?? '');
        return ['status' => self::mapStatus($raw), 'raw_status' => $raw ?: null, 'message' => data_get($json, 'transfer.failure_reason') ?? data_get($json, 'message'), 'raw' => $json];
    }

    // ------------------------------------------------------------ Solde

    /** { available: {XAF: …}, pending: {…} } */
    public function balance(): array
    {
        $json = $this->send('get', 'balance', grant: true);
        return ['available' => (array) data_get($json, 'balance.available', []), 'pending' => (array) data_get($json, 'balance.pending', []), 'raw' => $json];
    }

    // ------------------------------------------------------------ Utilitaires

    /**
     * complete → successful ; failed / canceled / expired → failed ; reste → pending.
     * $initial : statut renvoyé à la création (jamais « failed » sans motif : on revérifiera).
     */
    public static function mapStatus(?string $raw, bool $initial = false): string
    {
        $s = strtolower(trim((string) $raw));
        return match (true) {
            in_array($s, ['complete', 'completed', 'success', 'successful'], true) => 'successful',
            in_array($s, ['failed', 'canceled', 'cancelled', 'expired', 'rejected'], true) => $initial ? 'pending' : 'failed',
            default => 'pending',
        };
    }

    protected function send(string $method, string $path, array $body = [], bool $grant = false): array
    {
        if (! $this->enabled()) {
            throw new NotchPayException('Notch Pay non configuré (NOTCHPAY_ENABLED, NOTCHPAY_PUBLIC_KEY).', definitive: true);
        }
        $headers = ['Authorization' => (string) $this->cfg('public_key'), 'Accept' => 'application/json'];
        if ($pk = (string) $this->cfg('private_key')) {
            $headers['X-Grant'] = $pk;
        } elseif ($grant) {
            throw new NotchPayException('Clé privée Notch Pay manquante (NOTCHPAY_PRIVATE_KEY) : requise pour les versements et le solde.', definitive: true);
        }
        $url = rtrim((string) $this->cfg('base_url', 'https://api.notchpay.co'), '/') . '/' . ltrim($path, '/');

        try {
            $req = Http::withHeaders($headers)->timeout((int) $this->cfg('timeout', 30))->acceptJson();
            /** @var Response $res */
            $res = $method === 'get' ? $req->get($url, $body) : $req->asJson()->{$method}($url, $body);
        } catch (ConnectionException $e) {
            throw new NotchPayException('Notch Pay injoignable : ' . $e->getMessage());
        }
        $json = (array) ($res->json() ?? []);
        if ($res->failed()) {
            $errors = collect((array) data_get($json, 'errors', []))->flatten()->filter(fn ($v) => is_scalar($v))->implode(' ');
            $msg = trim((string) (data_get($json, 'message') ?: $res->reason()) . ($errors ? ' — ' . $errors : ''));
            Log::warning('Notch Pay : requête refusée', ['path' => $path, 'http' => $res->status(), 'response' => $json]);
            throw new NotchPayException("Notch Pay ({$res->status()}) : {$msg}", $res->status(), $json);
        }
        return $json;
    }
}
