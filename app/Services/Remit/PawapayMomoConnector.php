<?php

namespace App\Services\Remit;

use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use App\Services\Peex\PeexCorridors;
use Illuminate\Support\Facades\Log;

/**
 * Rail « pawapay_momo » du Switch : pawaPay comme passerelle NATIONALE / régionale
 * (pays confiés à pawaPay dans Console › Pays & change), ex. MTN MoMo et Airtel Money Congo.
 *
 *   collecte  → POST /v2/deposits  (le client valide le paiement sur son téléphone)
 *   versement → POST /v2/payouts
 *
 * Asynchrone : la demande revient « pending » ; le statut final arrive par callback
 * (/api/webhooks/pawapay) ou par `php artisan remit:sync`, et il est TOUJOURS relu
 * auprès de l'API pawaPay avant de valider ou de rembourser le client.
 * Les demandes sont journalisées dans remit_requests (partner = pawapay_momo,
 * method = deposit | payout).
 */
class PawapayMomoConnector implements PaymentRailConnector
{
    public function __construct(protected PawapayClient $client, protected PeexCorridors $corridors)
    {
    }

    public function railName(): string
    {
        return 'pawapay_momo';
    }

    public function collect(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        return $this->start('deposits', $reference . '-C', $tx, $msisdnOrAccount, $tx?->meta['source_country'] ?? null, $amountMinor, $currency);
    }

    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        return $this->start('payouts', $reference, $tx, $msisdnOrAccount, $tx?->meta['destination_country'] ?? null, $amountMinor, $currency);
    }

    /**
     * Remboursement du payeur par pawaPay (partenaire qui a collecté) : versement du montant
     * + frais sur le numéro débité (référence …-R, method = refund).
     */
    public function refund(Transaction $tx): array
    {
        return $this->start('payouts', $tx->reference . '-R', $tx, (string) $tx->source_account, $tx->meta['source_country'] ?? null,
            (int) $tx->amount + (int) $tx->fee, (string) $tx->currency, 'refund');
    }

    protected function start(string $kind, string $ref, ?Transaction $tx, string $msisdn, ?string $country, int $amount, string $currency, ?string $method = null): array
    {
        $method ??= $kind === 'deposits' ? 'deposit' : 'payout';
        $id = PawapayConnector::uuidFor($ref);
        $req = RemitRequest::firstOrCreate(['partner' => $this->railName(), 'reference' => $ref], [
            'transaction_id' => $tx?->id, 'partner' => $this->railName(), 'status' => 'new', 'method' => $method,
        ]);
        if ($req->partner_ref) {
            // Déjà envoyé (rejeu) : jamais deux fois — l'identifiant pawaPay est le même (DUPLICATE_IGNORED)
            return ['status' => $req->status === 'failed' ? 'failed' : ($req->status === 'successful' ? 'successful' : 'pending'), 'external_ref' => $req->partner_ref, 'raw' => (array) $req->last_response];
        }

        try {
            $route = $this->corridors->resolve($msisdn, $country);
            $p = $this->client->providerFor($route['phone']);
            $r = $this->client->initiate($kind, $id, [
                'phone' => $p['phone'], 'provider' => $p['provider'], 'amount' => $amount, 'currency' => $currency,
                'reference' => $tx?->reference ?? $ref,
                'message' => $method === 'deposit' ? 'FlashPay ' . substr(preg_replace('/\W/', '', $tx?->reference ?? $ref), -10) : ($method === 'refund' ? 'FlashPay rembours.' : 'FlashPay'),
            ]);
            $req->update(['partner_ref' => $id, 'status' => 'pending', 'raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now()]);
            if ($tx) {
                $tx->forceFill(['meta' => array_merge($tx->fresh()->meta ?? [], ['pawapay_' . $method => [
                    'at' => now()->toIso8601String(), 'endpoint' => 'POST v2/' . $kind, 'id' => $id, 'provider' => $p['provider'],
                    'sent' => $r['sent'], 'response' => $r['raw'],
                ]])])->save();
            }
            return ['status' => 'pending', 'external_ref' => $id, 'raw' => $r['raw']];
        } catch (RemitException $e) {
            // Refus explicite (REJECTED, numéro invalide…) : rien n'est parti. Sinon issue incertaine → vérifiée par l'identifiant.
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'partner_ref' => $definitive ? null : $id,
                'raw_status' => $e->partnerCode ? mb_substr($e->partnerCode, 0, 60) : null, 'message' => mb_substr($e->getMessage(), 0, 250), 'last_response' => $e->response ?: null, 'last_checked_at' => now()]);
            Log::warning("pawaPay : {$method} " . ($definitive ? 'refusé' : 'incertain'), ['reference' => $ref, 'error' => $e->getMessage()]);
            return ['status' => $definitive ? 'failed' : 'pending', 'external_ref' => $definitive ? $ref : $id, 'raw' => ['error' => $e->getMessage()]];
        } catch (\App\Services\Peex\PeexException $e) {
            $req->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'failed', 'external_ref' => $ref, 'raw' => ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            $req->update(['status' => 'pending', 'partner_ref' => $id, 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'pending', 'external_ref' => $id, 'raw' => ['error' => $e->getMessage()]];
        }
    }

    public function checkStatus(string $externalRef): array
    {
        $req = RemitRequest::where('partner', $this->railName())
            ->where(fn ($q) => $q->where('partner_ref', $externalRef)->orWhere('reference', $externalRef))->first();
        if (! $req || ! $req->partner_ref) {
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => [], 'checked' => false];
        }
        try {
            $r = $this->client->operationStatus($req->method === 'deposit' ? 'deposits' : 'payouts', $req->partner_ref);
        } catch (\Throwable $e) {
            $req->update(['last_checked_at' => now(), 'message' => mb_substr('Vérification : ' . $e->getMessage(), 0, 250)]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }
        // Inconnue chez pawaPay longtemps après un envoi incertain : elle n'est jamais arrivée → échec
        if ($r['raw_status'] === 'NOT_FOUND' && $req->created_at?->lt(now()->subMinutes((int) config('flashpay.pawapay.not_found_minutes', 2)))) {
            $r['status'] = 'failed';
        }
        $req->update(['raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now(),
            'message' => $r['failure'] ? mb_substr($r['failure'], 0, 250) : $req->message]);

        return ['status' => $r['status'], 'external_ref' => $externalRef, 'raw' => $r['raw'], 'checked' => true];
    }
}
