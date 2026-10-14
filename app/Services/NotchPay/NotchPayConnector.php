<?php

namespace App\Services\NotchPay;

use App\Models\NotchPayRequest;
use App\Models\Transaction;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use App\Services\Peex\PeexCorridors;
use Illuminate\Support\Facades\Log;

/**
 * Rail « notchpay » du Switch : collecte mobile money (direct charge) et
 * versements (transfers) pour les pays confiés à Notch Pay dans la console.
 *
 * Asynchrone : la demande revient « pending » ; le statut final arrive par
 * webhook (/api/webhooks/notchpay) ou par `php artisan notchpay:sync`, et il
 * est TOUJOURS revérifié auprès de l'API avant de valider ou de rembourser.
 */
class NotchPayConnector implements PaymentRailConnector
{
    public function __construct(protected NotchPayClient $client, protected PeexCorridors $corridors)
    {
    }

    public function railName(): string
    {
        return 'notchpay';
    }

    public function collect(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $meta = $tx?->meta ?? [];
        $ref = $reference . '-C';

        $req = NotchPayRequest::firstOrCreate(['reference' => $ref], [
            'transaction_id' => $tx?->id, 'operation' => 'payin', 'status' => 'new',
        ]);

        try {
            $route = $this->corridors->resolve($msisdnOrAccount, $meta['source_country'] ?? null);
            $channel = $this->client->channelFor($route['country'], $meta['source_operator'] ?? $route['operator'] ?? null);
            $req->update(['channel' => $channel]);
            $r = $this->client->charge($ref, $amountMinor, $currency, $route['phone'], $channel,
                $meta['payer_verified_name'] ?? $meta['payer_name'] ?? $tx?->initiator?->full_name ?? null,
                $tx?->initiator?->email ?? null, 'FlashPay ' . $reference);
            $req->update(['notch_ref' => $r['notch_ref'], 'status' => $r['status'], 'raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now()]);
            $this->keep($tx, 'notchpay_collect', $channel, $r);

            return ['status' => $r['status'], 'external_ref' => $r['notch_ref'], 'raw' => $r['raw']];
        } catch (NotchPayException $e) {
            return $this->failure($req, $e, $ref, 'collecte');
        } catch (\App\Services\Peex\PeexException $e) {
            return $this->failure($req, new NotchPayException($e->getMessage(), definitive: true), $ref, 'collecte');
        } catch (\Throwable $e) {
            $req->update(['status' => 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'pending', 'external_ref' => $ref, 'raw' => ['error' => $e->getMessage()]];
        }
    }

    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $meta = $tx?->meta ?? [];

        $req = NotchPayRequest::firstOrCreate(['reference' => $reference], [
            'transaction_id' => $tx?->id, 'operation' => 'payout', 'status' => 'new',
        ]);

        try {
            $route = $this->corridors->resolve($msisdnOrAccount, $meta['destination_country'] ?? null);
            $channel = $this->client->channelFor($route['country'], $meta['destination_operator'] ?? $route['operator'] ?? null);
            $req->update(['channel' => $channel]);
            $r = $this->client->transfer($reference, $amountMinor, $currency, $channel, $route['phone'],
                $meta['beneficiary_verified_name'] ?? $meta['beneficiary_name'] ?? 'Bénéficiaire FlashPay', $route['country'],
                $meta['purpose'] ?? null);
            $req->update(['notch_ref' => $r['notch_ref'], 'status' => $r['status'], 'raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now()]);
            $this->keep($tx, 'notchpay_payout', $channel, $r);

            return ['status' => $r['status'], 'external_ref' => $r['notch_ref'], 'raw' => $r['raw']];
        } catch (NotchPayException $e) {
            return $this->failure($req, $e, $reference, 'versement');
        } catch (\App\Services\Peex\PeexException $e) {
            return $this->failure($req, new NotchPayException($e->getMessage(), definitive: true), $reference, 'versement');
        } catch (\Throwable $e) {
            // Réseau : issue incertaine, revérifiée par notchpay:sync
            $req->update(['status' => 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'pending', 'external_ref' => $reference, 'raw' => ['error' => $e->getMessage()]];
        }
    }

    public function checkStatus(string $externalRef): array
    {
        $req = NotchPayRequest::where('notch_ref', $externalRef)->orWhere('reference', $externalRef)->first();
        try {
            $id = $req?->notch_ref ?: $externalRef;
            $r = $req?->operation === 'payin' ? $this->client->payment($id) : $this->client->transferStatus($id);
        } catch (\Throwable $e) {
            Log::warning('Notch Pay : statut illisible', ['reference' => $req?->reference, 'notch_ref' => $req?->notch_ref, 'error' => $e->getMessage()]);
            $req?->update(['last_checked_at' => now(), 'message' => mb_substr('Vérification : ' . $e->getMessage(), 0, 250)]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }
        $req?->update(['raw_status' => $r['raw_status'], 'message' => $r['message'] ? mb_substr((string) $r['message'], 0, 250) : $req->message,
            'last_response' => $r['raw'], 'last_checked_at' => now()]);

        return ['status' => $r['status'], 'external_ref' => $externalRef, 'raw' => $r['raw'], 'checked' => true];
    }

    /** Refus explicite = rien n'est parti : échec certain. Sinon issue incertaine → on vérifiera. */
    protected function failure(NotchPayRequest $req, NotchPayException $e, string $ref, string $what): array
    {
        $definitive = $e->isDefinitive();
        $req->update(['status' => $definitive ? 'failed' : 'pending', 'message' => mb_substr($e->getMessage(), 0, 250),
            'last_response' => $e->response ?: null, 'last_checked_at' => now()]);
        Log::warning("Notch Pay : {$what} " . ($definitive ? 'refusé(e)' : 'incertain(e)'), ['reference' => $ref, 'error' => $e->getMessage()]);

        return ['status' => $definitive ? 'failed' : 'pending', 'external_ref' => $req->notch_ref ?: $ref, 'raw' => ['error' => $e->getMessage()]];
    }

    /** Requête + réponse d'origine gardées sur la transaction (les vérifications de statut écrasent last_response). */
    protected function keep(?Transaction $tx, string $key, string $channel, array $r): void
    {
        if (! $tx) {
            return;
        }
        $tx->forceFill(['meta' => array_merge($tx->meta ?? [], [$key => [
            'at' => now()->toIso8601String(), 'channel' => $channel, 'notch_ref' => $r['notch_ref'],
            'sent' => $r['sent'] ?? null, 'response' => $r['raw'],
        ]])])->save();
    }
}
