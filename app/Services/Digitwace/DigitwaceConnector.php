<?php

namespace App\Services\Digitwace;

use App\Models\DigitwaceRequest;
use App\Models\Transaction;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use App\Services\Peex\PeexCorridors;
use Illuminate\Support\Facades\Log;

/**
 * Rail « digitwace » du Switch : versements (payout) vers les wallets mobile
 * money via WacePay. La collecte reste sur PEEX.
 *
 * Asynchrone comme PEEX : la demande revient « pending » ; le statut final
 * arrive par webhook (/api/webhooks/digitwace) ou par `php artisan digitwace:sync`,
 * et il est TOUJOURS revérifié auprès de l'API avant de rembourser le client.
 */
class DigitwaceConnector implements PaymentRailConnector
{
    public function __construct(protected DigitwaceClient $client, protected PeexCorridors $corridors)
    {
    }

    public function railName(): string
    {
        return 'digitwace';
    }

    /** Collecte (PAYIN) WacePay : le client valide sur son téléphone, statut asynchrone. */
    public function collect(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $meta = $tx?->meta ?? [];
        $route = $this->corridors->resolve($msisdnOrAccount, $meta['source_country'] ?? null);
        $ref = $reference . '-C';

        $req = DigitwaceRequest::firstOrCreate(['reference' => $ref], [
            'transaction_id' => $tx?->id, 'operation' => 'payin', 'status' => 'new',
        ]);

        try {
            $payer = $this->client->payerCodeFor($route['country'], $meta['source_operator'] ?? $route['operator'] ?? null, 'payin');
            if (! $payer) {
                throw new DigitwaceException("Collecte WacePay non disponible pour {$route['country']} (aucun payeur PAYIN — synchronisez la couverture).", '1001');
            }
            $r = $this->client->payin($ref, $payer, $amountMinor, $currency, $route['phone'],
                $meta['payer_verified_name'] ?? $meta['payer_name'] ?? $tx?->initiator?->full_name ?? 'Client FlashPay', $route['country']);
            $req->update(['wace_id' => $r['wace_id'], 'status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'last_response' => $r['raw'], 'last_checked_at' => now()]);

            return ['status' => $r['status'] === 'successful' ? 'successful' : ($r['status'] === 'failed' ? 'failed' : 'pending'), 'external_ref' => $r['wace_id'] ?: $ref, 'raw' => $r['raw']];
        } catch (DigitwaceException $e) {
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_response' => $e->response, 'last_checked_at' => now()]);
            return ['status' => $definitive ? 'failed' : 'pending', 'external_ref' => $ref, 'raw' => ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            $req->update(['status' => 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'pending', 'external_ref' => $ref, 'raw' => ['error' => $e->getMessage()]];
        }
    }

    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $meta = $tx?->meta ?? [];
        $route = $this->corridors->resolve($msisdnOrAccount, $meta['destination_country'] ?? null);

        $req = DigitwaceRequest::firstOrCreate(['reference' => $reference], [
            'transaction_id' => $tx?->id, 'operation' => 'payout', 'status' => 'new',
        ]);

        try {
            $payer = $this->client->payerCodeFor($route['country'], $meta['destination_operator'] ?? null);
            if (! $payer) {
                throw new DigitwaceException("Aucun payerCode WacePay pour {$route['country']} / " . ($meta['destination_operator'] ?? 'opérateur inconnu') . ' (DIGITWACE_PAYER_CODES).', '1001');
            }
            $sender = $this->client->senderCode([
                'name' => $meta['sender_name'] ?? $tx?->initiator?->full_name ?? 'Client FlashPay',
                'phone' => (string) ($meta['sender_phone'] ?? $tx?->initiator?->phone ?? ''),
                'country' => $meta['source_country'] ?? config('flashpay.peex.sender_country', 'CG'),
            ]);
            $beneficiary = $this->client->beneficiaryCode([
                'name' => $meta['beneficiary_verified_name'] ?? $meta['beneficiary_name'] ?? 'Beneficiaire FlashPay',
                'phone' => $route['phone'],
                'country' => $route['country'],
            ]);

            $r = $this->client->payout($reference, $sender, $beneficiary, $payer, $amountMinor, $currency, $route['phone'], $meta['purpose'] ?? null);
            $req->update(['wace_id' => $r['wace_id'], 'status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'last_response' => $r['raw'], 'last_checked_at' => now()]);

            return ['status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'external_ref' => $r['wace_id'] ?: $reference, 'raw' => $r['raw']];
        } catch (DigitwaceException $e) {
            // Refus explicite = rien n'est parti : échec certain. Sinon issue incertaine → on vérifiera.
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_response' => $e->response, 'last_checked_at' => now()]);
            Log::warning('WacePay : versement ' . ($definitive ? 'refusé' : 'incertain'), ['reference' => $reference, 'error' => $e->getMessage()]);

            return $definitive
                ? ['status' => 'failed', 'external_ref' => $reference, 'raw' => ['error' => $e->getMessage()]]
                : ['status' => 'pending', 'external_ref' => $reference, 'raw' => ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            // Réseau / verrou de file : issue incertaine, revérifiée par digitwace:sync
            $req->update(['status' => 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'pending', 'external_ref' => $reference, 'raw' => ['error' => $e->getMessage()]];
        }
    }

    public function checkStatus(string $externalRef): array
    {
        $req = DigitwaceRequest::where('wace_id', $externalRef)->orWhere('reference', $externalRef)->first();
        try {
            $r = $this->client->status($req?->wace_id ?: $externalRef);
        } catch (\Throwable $e) {
            $req?->update(['last_checked_at' => now()]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }
        $req?->update(['raw_status' => $r['raw_status'], 'message' => $r['message'] ? mb_substr((string) $r['message'], 0, 250) : $req->message, 'last_response' => $r['raw'], 'last_checked_at' => now()]);

        return ['status' => $r['status'], 'external_ref' => $externalRef, 'raw' => $r['raw'], 'checked' => true];
    }
}
