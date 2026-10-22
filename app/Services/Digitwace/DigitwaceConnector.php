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
                $op = \App\Services\Digitwace\DigitwaceClient::operatorCode($meta['source_operator'] ?? $route['operator'] ?? null);
                throw new DigitwaceException('Collecte WacePay non disponible' . ($op ? " pour les numéros {$op}" : '') . " ({$route['country']}) : aucun service de collecte WacePay" . ($op ? " {$op}" : '') . ' sur votre compte. Activez-le chez WacePay puis synchronisez la couverture.', '1001');
            }
            // WacePay facture sa commission de collecte AU PAYEUR, en plus du montant demandé
            // (feeBearer PAYER : 4 270 demandés → 4 356 débités). Les frais de collecte déjà
            // inclus dans le devis OfficePay sont donc retirés du montant demandé, sinon
            // l'expéditeur paierait la collecte deux fois.
            $collectFee = (int) data_get($meta, 'fee_breakdown.collect', 0);
            if ($collectFee > 0 && config('flashpay.digitwace.collect_fee_bearer', 'payer') === 'payer' && $collectFee < $amountMinor) {
                $amountMinor -= $collectFee;
            }
            $r = $this->client->payin($ref, $payer, $amountMinor, $currency, $route['phone'],
                $meta['payer_verified_name'] ?? $meta['payer_name'] ?? $tx?->initiator?->full_name ?? 'Client OfficePay', $route['country'],
                $meta['source_operator'] ?? null, $tx?->initiator?->email ?? null);
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

        return $this->payoutTo($reference, 'payout', $tx, $msisdnOrAccount, $meta['destination_country'] ?? null, $meta['destination_operator'] ?? null,
            $meta['beneficiary_verified_name'] ?? $meta['beneficiary_name'] ?? null, $amountMinor, $currency);
    }

    /**
     * Remboursement du payeur par WacePay (partenaire qui a collecté) : versement du montant
     * + frais sur le numéro débité (référence …-R, operation = refund).
     */
    public function refund(Transaction $tx): array
    {
        $meta = $tx->meta ?? [];
        return $this->payoutTo($tx->reference . '-R', 'refund', $tx, (string) $tx->source_account, $meta['source_country'] ?? null, $meta['source_operator'] ?? null,
            $meta['payer_verified_name'] ?? $meta['payer_name'] ?? $meta['sender_name'] ?? $tx->initiator?->full_name, (int) $tx->amount + (int) $tx->fee, (string) $tx->currency);
    }

    /** Versement WacePay (bénéficiaire, ou payeur pour un remboursement). */
    protected function payoutTo(string $reference, string $operation, ?Transaction $tx, string $msisdnOrAccount, ?string $country, ?string $operator, ?string $name, int $amountMinor, string $currency): array
    {
        $meta = $tx?->meta ?? [];
        $route = $this->corridors->resolve($msisdnOrAccount, $country);
        $refund = $operation === 'refund';

        $req = DigitwaceRequest::firstOrCreate(['reference' => $reference], [
            'transaction_id' => $tx?->id, 'operation' => $operation, 'status' => 'new',
        ]);

        try {
            $payer = $this->client->payerCodeFor($route['country'], $operator ?? $route['operator'] ?? null);
            if (! $payer) {
                throw new DigitwaceException("Aucun service de versement WacePay pour {$route['country']} / " . ($operator ?? 'opérateur inconnu') . ' (DIGITWACE_PAYER_CODES).', '1001');
            }
            if ($this->client->partner()) {
                // API Partenaire : un seul appel (payout/execute), payerCode = payoutSubscriptionId
                $r = $this->client->payoutDirect($reference, $payer, $amountMinor, $route['phone'], $name);
                $req->update(['wace_id' => $r['wace_id'], 'status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'last_response' => $r['raw'], 'last_checked_at' => now()]);
                // Requête + réponse de payout/execute gardées sur la transaction (les vérifications de statut écrasent last_response)
                if ($tx) {
                    $tx->forceFill(['meta' => array_merge($tx->fresh()->meta ?? [], [($refund ? 'wacepay_refund' : 'wacepay_payout') => [
                        'at' => now()->toIso8601String(), 'endpoint' => 'POST payout/execute', 'service_id' => $payer,
                        'sent' => $r['sent'] ?? null, 'response' => $r['raw'], 'wace_id' => $r['wace_id'],
                    ]])])->save();
                }

                return ['status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'external_ref' => $r['wace_id'] ?: $reference, 'raw' => $r['raw']];
            }
            $sender = $this->client->senderCode($refund ? [
                'name' => 'OfficePay Remboursement', 'phone' => (string) config('flashpay.peex.sender_phone', ''),
                'country' => config('flashpay.peex.sender_country', 'CG'),
            ] : [
                'name' => $meta['sender_name'] ?? $tx?->initiator?->full_name ?? 'Client OfficePay',
                'phone' => (string) ($meta['sender_phone'] ?? $tx?->initiator?->phone ?? ''),
                'country' => $meta['source_country'] ?? config('flashpay.peex.sender_country', 'CG'),
            ]);
            $beneficiary = $this->client->beneficiaryCode([
                'name' => $name ?: 'Beneficiaire OfficePay',
                'phone' => $route['phone'],
                'country' => $route['country'],
            ]);

            $r = $this->client->payout($reference, $sender, $beneficiary, $payer, $amountMinor, $currency, $route['phone'], $meta['purpose'] ?? null,
                $route['country'], $meta['source_country'] ?? config('flashpay.peex.sender_country', 'CG'), $tx?->currency);
            $req->update(['wace_id' => $r['wace_id'], 'status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'last_response' => $r['raw'], 'last_checked_at' => now()]);

            return ['status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'external_ref' => $r['wace_id'] ?: $reference, 'raw' => $r['raw']];
        } catch (DigitwaceException $e) {
            // Refus explicite = rien n'est parti : échec certain. Sinon issue incertaine → on vérifiera.
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_response' => $e->response, 'last_checked_at' => now()]);
            Log::warning('WacePay : ' . ($refund ? 'remboursement' : 'versement') . ' ' . ($definitive ? 'refusé' : 'incertain'), ['reference' => $reference, 'error' => $e->getMessage()]);

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
            $op = $req?->operation;
            // Collecte (API Partenaire) : statut par referenceId ; versement : par id WacePay
            $id = (in_array($op, ['payin', 'checkout'], true) && $this->client->partner()) ? ($req?->reference ?: $externalRef) : ($req?->wace_id ?: $externalRef);
            $r = $this->client->status($id, $op, $req?->reference);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('WacePay : statut illisible', ['reference' => $req?->reference, 'wace_id' => $req?->wace_id, 'error' => $e->getMessage()]);
            $req?->update(['last_checked_at' => now(), 'message' => mb_substr('Vérification : ' . $e->getMessage(), 0, 250)]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }
        $req?->update(['raw_status' => $r['raw_status'], 'message' => $r['message'] ? mb_substr((string) $r['message'], 0, 250) : $req->message, 'last_response' => $r['raw'], 'last_checked_at' => now()]);

        return ['status' => $r['status'], 'external_ref' => $externalRef, 'raw' => $r['raw'], 'checked' => true];
    }
}
