<?php

namespace App\Services\Maishapay;

use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use App\Services\Peex\PeexCorridors;
use App\Services\Remit\RemitException;
use Illuminate\Support\Facades\Log;

/**
 * Rail « maishapay » du Switch : collecte mobile money, versement mobile money (B2C),
 * remboursement du payeur (versement B2C vers le numéro débité) et page de paiement carte.
 *
 * Asynchrone : la demande revient « pending » ; le statut final arrive par callback
 * (/api/webhooks/maishapay) ou par `php artisan remit:sync`, et il est TOUJOURS relu
 * auprès de l'API MaishaPay (transaction/rest/v2/check) avant de valider ou de rembourser.
 * Demandes journalisées dans remit_requests (partner = maishapay,
 * method = deposit | payout | refund | checkout).
 */
class MaishapayConnector implements PaymentRailConnector
{
    public function __construct(protected MaishapayClient $client, protected PeexCorridors $corridors)
    {
    }

    public function railName(): string
    {
        return 'maishapay';
    }

    public function collect(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $m = $tx?->meta ?? [];
        return $this->start('deposit', $reference . '-C', $tx, $msisdnOrAccount, $m['source_country'] ?? null, $m['source_operator'] ?? null,
            $amountMinor, $currency, $m['payer_verified_name'] ?? $m['payer_name'] ?? $tx?->initiator?->full_name);
    }

    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $m = $tx?->meta ?? [];
        return $this->start('payout', $reference, $tx, $msisdnOrAccount, $m['destination_country'] ?? null, $m['destination_operator'] ?? null,
            $amountMinor, $currency, $m['beneficiary_verified_name'] ?? $m['beneficiary_name'] ?? null, $m['purpose'] ?? null);
    }

    /** Remboursement du payeur par MaishaPay (partenaire qui a collecté) : versement montant + frais (…-R). */
    public function refund(Transaction $tx): array
    {
        $m = $tx->meta ?? [];
        return $this->start('refund', $tx->reference . '-R', $tx, (string) $tx->source_account, $m['source_country'] ?? null, $m['source_operator'] ?? null,
            (int) $tx->amount + (int) $tx->fee, (string) $tx->currency, $m['payer_verified_name'] ?? $m['payer_name'] ?? $tx->initiator?->full_name, 'Remboursement FlashPay');
    }

    protected function start(string $method, string $ref, ?Transaction $tx, string $msisdn, ?string $country, ?string $operator, int $amount, string $currency, ?string $name, ?string $motif = null): array
    {
        $req = RemitRequest::firstOrCreate(['partner' => $this->railName(), 'reference' => $ref], [
            'transaction_id' => $tx?->id, 'partner' => 'maishapay', 'status' => 'new', 'method' => $method,
        ]);
        if ($req->partner_ref || in_array($req->status, ['pending', 'successful'], true)) {
            // Déjà envoyé : jamais deux fois (même référence chez MaishaPay)
            return ['status' => $req->status === 'failed' ? 'failed' : ($req->status === 'successful' ? 'successful' : 'pending'), 'external_ref' => $req->partner_ref ?: $ref, 'raw' => (array) $req->last_response];
        }

        try {
            $route = $this->corridors->resolve($msisdn, $country);
            $provider = MaishapayClient::provider($operator ?? $route['operator'] ?? null);
            if (! $provider) {
                throw new RemitException('MaishaPay : opérateur mobile money non reconnu pour ' . $route['phone'] . '.', 'PROVIDER', null, [], true);
            }
            $p = ['phone' => $route['phone'], 'provider' => $provider, 'amount' => $amount, 'currency' => $currency, 'name' => $name,
                'email' => $tx?->initiator?->email, 'motif' => $motif ?: 'FlashPay ' . ($tx?->reference ?? $ref)];
            $r = $method === 'deposit' ? $this->client->collectMobile($ref, $p) : $this->client->payoutMobile($ref, $p);

            $req->update(['partner_ref' => $r['id'] ?: $ref, 'status' => $r['status'] === 'successful' ? 'successful' : 'pending',
                'raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now()]);
            if ($tx) {
                $tx->forceFill(['meta' => array_merge($tx->fresh()->meta ?? [], ['maishapay_' . $method => [
                    'at' => now()->toIso8601String(), 'id' => $r['id'], 'provider' => $provider, 'sent' => $r['sent'], 'response' => $r['raw'],
                ]])])->save();
            }
            // Versement / remboursement : MaishaPay peut répondre SUCCESS tout de suite ; on le relira quand même
            return ['status' => 'pending', 'external_ref' => $r['id'] ?: $ref, 'raw' => $r['raw']];
        } catch (RemitException $e) {
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'partner_ref' => $definitive ? null : $ref,
                'raw_status' => $e->partnerCode ? mb_substr($e->partnerCode, 0, 60) : null, 'message' => mb_substr($e->getMessage(), 0, 250),
                'last_response' => $e->response ?: null, 'last_checked_at' => now()]);
            Log::warning("MaishaPay : {$method} " . ($definitive ? 'refusé' : 'incertain'), ['reference' => $ref, 'error' => $e->getMessage()]);
            return ['status' => $definitive ? 'failed' : 'pending', 'external_ref' => $ref, 'raw' => ['error' => $e->getMessage()]];
        } catch (\App\Services\Peex\PeexException $e) {
            $req->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'failed', 'external_ref' => $ref, 'raw' => ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            // Réseau : issue incertaine → vérifiée par notre référence
            $req->update(['status' => 'pending', 'partner_ref' => $ref, 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now()]);
            return ['status' => 'pending', 'external_ref' => $ref, 'raw' => ['error' => $e->getMessage()]];
        }
    }

    public function checkStatus(string $externalRef): array
    {
        $req = RemitRequest::where('partner', 'maishapay')
            ->where(fn ($q) => $q->where('partner_ref', $externalRef)->orWhere('reference', $externalRef))->first();
        if (! $req) {
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => [], 'checked' => false];
        }
        try {
            // Identifiant MaishaPay connu : recherche par id ; sinon par notre référence
            $byRef = ! $req->partner_ref || $req->partner_ref === $req->reference;
            $r = $this->client->check($byRef ? $req->reference : $req->partner_ref, $byRef);
            if (! $r['found'] && ! $byRef) {
                $r = $this->client->check($req->reference, true);
            }
        } catch (\Throwable $e) {
            $req->update(['last_checked_at' => now(), 'message' => mb_substr('Vérification : ' . $e->getMessage(), 0, 250)]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }
        // Inconnue chez MaishaPay longtemps après l'envoi : jamais arrivée → échec
        if (! $r['found'] && $req->created_at?->lt(now()->subMinutes((int) config('flashpay.maishapay.not_found_minutes', 2)))) {
            $r['status'] = 'failed';
            $r['failure'] = 'Opération introuvable chez MaishaPay';
        }
        if ($id = MaishapayClient::idOf($r['raw'])) {
            $req->partner_ref = $id;
        }
        $req->fill(['raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now(),
            'message' => $r['failure'] ? mb_substr($r['failure'], 0, 250) : $req->message])->save();

        return ['status' => $r['status'], 'external_ref' => $externalRef, 'raw' => $r['raw'], 'checked' => true];
    }
}
