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
 *   collect()   -> POST collection/request_payment           (débit mobile money)       track_id …-C1
 *   disburse()  -> POST disbursement/request_payment         (crédit mobile money)      track_id …-D1
 *                  ou POST clients/request_payment (remittance) selon le pays
 *   refund()    -> même API de versement, vers le numéro PAYEUR (remboursement)  track_id …-R1
 *   checkStatus -> GET  {collection|disbursement|clients}/all_requests?track_id=
 *
 * PEEX est ASYNCHRONE : une demande revient en "new"/"pending" ; le statut
 * final (paid / failed / rejected / canceled) arrive par callback
 * (WebhookController::peex) ou par polling (php artisan peex:sync).
 *
 * Erreurs d'appel :
 *   - refus explicite (4xx)            -> "error" : PEEX n'a rien exécuté, échec certain ;
 *   - coupure réseau / 408 / 429 / 5xx -> "unknown" : issue incertaine, la demande reste
 *     en attente et son statut est vérifié auprès de PEEX (peex:sync) avant toute décision.
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
            'customer_name' => $meta['payer_verified_name'] ?? $meta['payer_name'] ?? $tx?->initiator?->full_name ?? 'Client FlashPay',
            'country' => $route['country'],
            'description' => $meta['description'] ?? "FlashPay {$reference}",
        ];

        return $this->send('collect', $payload, $route, $tx, fn () => $this->client->collect($payload));
    }

    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $meta = $tx?->meta ?? [];

        return $this->payout($tx, $reference, 'D', $msisdnOrAccount, $amountMinor, $currency, [
            'country_hint' => $meta['destination_country'] ?? null,
            'beneficiary' => $meta['beneficiary_verified_name'] ?? $meta['beneficiary_name'] ?? 'Beneficiaire FlashPay',
            'sender' => $meta['sender_name'] ?? $tx?->initiator?->full_name ?? 'FlashPay Congo',
            'sender_phone' => $meta['sender_phone'] ?? $tx?->initiator?->phone ?? '242060000000',
            'sender_country' => $meta['source_country'] ?? null,
            'purpose' => $meta['purpose'] ?? null,
            'fund_origin' => $meta['fund_origin'] ?? null,
        ]);
    }

    /**
     * Remboursement du PAYEUR (source mobile money) après échec définitif du
     * versement : montant + frais sont reversés sur le numéro débité.
     */
    public function refund(Transaction $tx): array
    {
        $meta = $tx->meta ?? [];
        $payer = $meta['payer_verified_name'] ?? $meta['payer_name'] ?? $meta['sender_name'] ?? $tx->initiator?->full_name ?? 'Client FlashPay';

        return $this->payout($tx, $tx->reference, 'R', (string) $tx->source_account, (int) $tx->amount + (int) $tx->fee, $tx->currency, [
            'country_hint' => $meta['source_country'] ?? null,
            'beneficiary' => $payer,
            'sender' => 'FlashPay Remboursement',
            'sender_phone' => $tx->source_account,
            'sender_country' => $meta['source_country'] ?? null,
            'purpose' => $meta['purpose'] ?? null,
            'fund_origin' => $meta['fund_origin'] ?? null,
        ]);
    }

    /**
     * $externalRef = track_id PEEX. Interroge PEEX et met à jour peex_requests.
     * Une demande « unknown » (appel interrompu) introuvable chez PEEX après le
     * délai de grâce est déclarée jamais reçue : échec certain.
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
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }

        if ($item) {
            $req->fill([
                'status' => strtolower($item['status'] ?? $req->status),
                'peex_id' => $item['id'] ?? $req->peex_id,
                'payment_proof' => $this->str($item['payment_proof'] ?? $req->payment_proof),
                'message' => $this->str($item['message'] ?? $req->message),
            ]);
        } elseif ($req->status === 'unknown'
            && $req->created_at->lt(now()->subMinutes(config('flashpay.peex.unknown_grace_minutes', 10)))) {
            $req->fill(['status' => 'error', 'message' => 'Demande non reçue par PEEX (vérifiée par all_requests)']);
        }
        $req->last_checked_at = now();
        $req->save();

        return ['status' => PeexRequest::normalize($req->status), 'external_ref' => $externalRef, 'raw' => $item, 'checked' => true];
    }

    // ------------------------------------------------------------------------

    /** Versement vers un numéro mobile money (bénéficiaire « D » ou remboursement « R »). */
    protected function payout(?Transaction $tx, string $reference, string $kind, string $msisdn, int $amount, string $currency, array $o): array
    {
        $route = $this->corridors->resolve($msisdn, $o['country_hint'] ?? null);
        $currency = $currency ?: $route['currency'];
        $country = $this->corridors->country($route['country']);

        if (! ($country['payout'] ?? false)) {
            return $this->reject("Décaissement PEEX non activé pour {$country['name']}", $reference);
        }

        [$senderFirst, $senderLast] = $this->splitName($o['sender']);
        [$first, $last] = $this->splitName($o['beneficiary']);
        $senderPhone = $this->corridors->resolve($o['sender_phone'])['phone'];

        $common = [
            'track_id' => $this->trackId($reference, $kind),
            'mobile_phone' => $route['phone'],
            'amount' => $amount,
            'sender_first_name' => $senderFirst,
            'sender_last_name' => $senderLast,
            'sender_mobile_phone' => $senderPhone,
            'first_name' => $first,
            'last_name' => $last,
            'purpose' => $o['purpose'] ?? config('flashpay.peex.default_purpose'),
            'fund_origin' => $o['fund_origin'] ?? config('flashpay.peex.default_fund_origin'),
        ];

        if (($country['payout_api'] ?? 'disbursement') === 'remittance') {
            // Le change est calculé par FlashPay (FxService) : on transmet le montant
            // déjà converti dans la devise du bénéficiaire, avec fxrate = 1 comme
            // l'exige la doc PEEX. ⚠ À confirmer avec PEEX pour CDF / GNF.
            $payload = $common + [
                'from_currency' => $currency,
                'to_currency' => $currency,
                'fxrate' => 1,
                'aml_cft' => 1,
                'sender_country' => $o['sender_country'] ?? config('flashpay.peex.sender_country', 'CG'),
                'to_country' => $route['country'],
            ];

            return $this->send('remittance', $payload, $route, $tx, fn () => $this->client->remit($payload));
        }

        $payload = $common + [
            'currency' => $currency,
            'country' => $route['country'],
        ];

        return $this->send('disbursement', $payload, $route, $tx, fn () => $this->client->disburse($payload));
    }

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
            if ($e->isDefinitive()) {
                // Refus explicite : PEEX n'a pas exécuté la demande.
                Log::error("PEEX {$service} refusé", ['track_id' => $req->track_id, 'error' => $e->getMessage()]);
                $req->update(['status' => 'error', 'message' => $e->getMessage(), 'response_payload' => $e->body, 'finalized_at' => now()]);

                return ['status' => 'failed', 'external_ref' => $req->track_id, 'raw' => ['error' => $e->getMessage()] + $e->body];
            }

            // Issue incertaine : PEEX a pu recevoir la demande. On NE conclut PAS à
            // l'échec : statut vérifié ensuite via all_requests (peex:sync).
            Log::warning("PEEX {$service} : réponse non reçue, statut à vérifier", ['track_id' => $req->track_id, 'error' => $e->getMessage()]);
            $req->update(['status' => 'unknown', 'message' => $e->getMessage(), 'response_payload' => $e->body ?: null]);

            return ['status' => 'pending', 'external_ref' => $req->track_id, 'raw' => ['warning' => $e->getMessage()]];
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

    /** track_id unique par tentative : FP-XXXX-C1, FP-XXXX-D1, FP-XXXX-R1… */
    protected function trackId(string $reference, string $kind): string
    {
        $n = PeexRequest::where('track_id', 'like', "{$reference}-{$kind}%")->count() + 1;
        return "{$reference}-{$kind}{$n}";
    }

    /** Étape d'une demande d'après son track_id : C (collecte), D (versement), R (remboursement). */
    public static function legOf(string $trackId): string
    {
        return preg_match('/-([CDR])\d+$/', $trackId, $m) ? $m[1] : 'D';
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
