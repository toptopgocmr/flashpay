<?php

namespace App\Services\Connectors;

use App\Models\PeexRequest;
use App\Models\Transaction;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use App\Services\Peex\PeexClient;
use App\Services\Peex\PeexCorridors;
use App\Services\Peex\PeexException;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur PEEX (https://peex-api-docs.peexit.com/).
 *
 *   collect()   -> POST collection/request_payment           (débit mobile money)
 *   disburse()  -> POST disbursement/request_payment         (crédit mobile money)
 *                  ou POST clients/request_payment (remittance) selon le pays
 *   checkStatus -> GET  {collection|disbursement|clients}/all_requests?track_id=
 *
 * PEEX est ASYNCHRONE : une demande revient en "new"/"pending" ; le statut
 * final (paid / failed / rejected / canceled) arrive par callback
 * (WebhookController::peex) ou par polling (php artisan peex:sync).
 * Chaque appel est journalisé dans la table peex_requests.
 */
class PeexConnector implements PaymentRailConnector
{
    public function __construct(
        protected PeexClient $client,
        protected PeexCorridors $corridors,
    ) {
    }

    public function railName(): string
    {
        return 'peex';
    }

    public function collect(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $meta = $tx?->meta ?? [];
        $route = $this->corridors->resolve($msisdnOrAccount, $meta['source_country'] ?? null);
        $country = $this->corridors->country($route['country']);

        if (! ($country['collect'] ?? false)) {
            return $this->reject("Collecte PEEX non activée pour {$country['name']}", $reference);
        }

        $payload = [
            'track_id' => $this->trackId($reference, 'C'),
            'phone' => $route['phone'],
            'amount' => $amountMinor,
            'currency' => $currency ?: $route['currency'],
            'customer_name' => $meta['payer_name'] ?? $tx?->initiator?->full_name ?? 'Client FlashPay',
            'country' => $route['country'],
            'description' => $meta['description'] ?? "FlashPay {$reference}",
        ];

        return $this->send('collect', $payload, $route, $tx, fn () => $this->client->collect($payload));
    }

    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $meta = $tx?->meta ?? [];
        $route = $this->corridors->resolve($msisdnOrAccount, $meta['destination_country'] ?? null);
        $currency = $currency ?: $route['currency'];
        $country = $this->corridors->country($route['country']);

        if (! ($country['payout'] ?? false)) {
            return $this->reject("Décaissement PEEX non activé pour {$country['name']}", $reference);
        }

        [$senderFirst, $senderLast] = $this->splitName($meta['sender_name'] ?? $tx?->initiator?->full_name ?? 'FlashPay Congo');
        [$first, $last] = $this->splitName($meta['beneficiary_name'] ?? 'Beneficiaire FlashPay');
        $senderPhone = $this->corridors->resolve(
            $meta['sender_phone'] ?? $tx?->initiator?->phone ?? '242060000000'
        )['phone'];

        $common = [
            'track_id' => $this->trackId($reference, 'D'),
            'mobile_phone' => $route['phone'],
            'amount' => $amountMinor,
            'sender_first_name' => $senderFirst,
            'sender_last_name' => $senderLast,
            'sender_mobile_phone' => $senderPhone,
            'first_name' => $first,
            'last_name' => $last,
            'purpose' => $meta['purpose'] ?? config('flashpay.peex.default_purpose'),
            'fund_origin' => $meta['fund_origin'] ?? config('flashpay.peex.default_fund_origin'),
        ];

        if (($country['payout_api'] ?? 'disbursement') === 'remittance') {
            // Le change est calculé par FlashPay (FxService) : on transmet le montant
            // déjà converti dans la devise du bénéficiaire, avec fxrate = 1 comme
            // l'exige la doc PEEX. ⚠ À confirmer avec PEEX pour CDF / GNF.
            $payload = $common + [
                'from_currency' => $currency ?: $route['currency'],
                'to_currency' => $currency ?: $route['currency'],
                'fxrate' => 1,
                'aml_cft' => 1,
                'sender_country' => $meta['source_country'] ?? config('flashpay.peex.sender_country', 'CG'),
                'to_country' => $route['country'],
            ];


            return $this->send('remittance', $payload, $route, $tx, fn () => $this->client->remit($payload));
        }

        $payload = $common + [
            'currency' => $currency ?: $route['currency'],
            'country' => $route['country'],
        ];

        return $this->send('disbursement', $payload, $route, $tx, fn () => $this->client->disburse($payload));
    }

    /**
     * $externalRef = track_id PEEX. Interroge PEEX et met à jour peex_requests.
     */
    public function checkStatus(string $externalRef): array
    {
        $req = PeexRequest::where('track_id', $externalRef)->first();
        if (! $req) {
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => []];
        }

        try {
            $item = $this->client->statusFor($req->service, $req->track_id);
        } catch (PeexException $e) {
            $req->update(['last_checked_at' => now()]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()]];
        }

        if ($item) {
            $req->fill([
                'status' => strtolower($item['status'] ?? $req->status),
                'peex_id' => $item['id'] ?? $req->peex_id,
                'payment_proof' => $this->str($item['payment_proof'] ?? $req->payment_proof),
                'message' => $this->str($item['message'] ?? $req->message),
            ]);
        }
        $req->last_checked_at = now();
        $req->save();

        return ['status' => PeexRequest::normalize($req->status), 'external_ref' => $externalRef, 'raw' => $item];
    }

    // ------------------------------------------------------------------------

    protected function send(string $service, array $payload, array $route, ?Transaction $tx, callable $call): array
    {
        $req = PeexRequest::create([
            'transaction_id' => $tx?->id,
            'service' => $service,
            'track_id' => $payload['track_id'],
            'country' => $route['country'],
            'corridor' => $route['corridor'],
            'phone' => $route['phone'],
            'amount' => (int) $payload['amount'],
            'currency' => $payload['currency'] ?? $payload['from_currency'] ?? 'XAF',
            'status' => 'new',
            'sandbox' => $this->client->isSandbox(),
            'request_payload' => $payload,
        ]);

        try {
            $body = $call();
        } catch (PeexException $e) {
            Log::error("PEEX {$service} échoué", ['track_id' => $req->track_id, 'error' => $e->getMessage()]);
            $req->update(['status' => 'error', 'message' => $e->getMessage(), 'response_payload' => $e->body, 'finalized_at' => now()]);

            return ['status' => 'failed', 'external_ref' => $req->track_id, 'raw' => ['error' => $e->getMessage()] + $e->body];
        }

        $item = PeexClient::extractRequest($body, $req->track_id) ?? [];
        $req->update([
            'status' => strtolower($item['status'] ?? 'new'),
            'peex_id' => $item['id'] ?? null,
            'payment_proof' => $this->str($item['payment_proof'] ?? null),
            'message' => $this->str($item['message'] ?? null),
            'response_payload' => $body,
        ]);

        return [
            'status' => PeexRequest::normalize($req->status),
            'external_ref' => $req->track_id,
            'raw' => $body,
        ];
    }

    protected function reject(string $reason, string $reference): array
    {
        return ['status' => 'failed', 'external_ref' => $reference, 'raw' => ['error' => $reason]];
    }

    /** track_id unique par tentative : FP-XXXX-C1, FP-XXXX-D1, FP-XXXX-D2... */
    protected function trackId(string $reference, string $kind): string
    {
        $n = PeexRequest::where('track_id', 'like', "{$reference}-{$kind}%")->count() + 1;
        return "{$reference}-{$kind}{$n}";
    }

    protected function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $first = array_shift($parts) ?: 'Client';
        $last = implode(' ', $parts) ?: 'FlashPay';
        return [$first, $last];
    }

    protected function str(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
