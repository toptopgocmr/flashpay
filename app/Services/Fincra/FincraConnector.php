<?php

namespace App\Services\Fincra;

use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use App\Services\Peex\PeexCorridors;
use App\Services\Remit\RemitException;
use Illuminate\Support\Facades\Log;

/**
 * Rail « fincra » du Switch (passerelle nationale) : collecte mobile money (Direct Charge),
 * versement mobile money (payout mobile_money_wallet) et remboursement du payeur
 * (versement vers le numéro débité, référence …-R).
 *
 * Asynchrone : la demande revient « pending » ; le statut final arrive par webhook
 * (/api/webhooks/fincra) ou par `php artisan remit:sync`, et il est TOUJOURS relu auprès
 * de l'API Fincra (verify charge / payout par customerReference) avant de valider ou de rembourser.
 * Demandes journalisées dans remit_requests (partner = fincra, method = deposit | payout | refund).
 */
class FincraConnector implements PaymentRailConnector
{
    public function __construct(protected FincraClient $client, protected PeexCorridors $corridors)
    {
    }

    public function railName(): string
    {
        return 'fincra';
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

    /** Remboursement du payeur par Fincra (partenaire qui a collecté) : versement montant + frais (…-R). */
    public function refund(Transaction $tx): array
    {
        $m = $tx->meta ?? [];
        return $this->start('refund', $tx->reference . '-R', $tx, (string) $tx->source_account, $m['source_country'] ?? null, $m['source_operator'] ?? null,
            (int) $tx->amount + (int) $tx->fee, (string) $tx->currency, $m['payer_verified_name'] ?? $m['payer_name'] ?? $tx->initiator?->full_name, 'Remboursement OfficePay');
    }

    /** Prénom / nom (Fincra exige firstName + lastName pour un particulier). */
    public static function splitName(?string $name, string $fallback = 'Beneficiaire OfficePay'): array
    {
        $parts = preg_split('/\s+/', trim((string) $name) ?: $fallback);
        $first = array_shift($parts);
        return [$first, $parts ? implode(' ', $parts) : $first];
    }

    protected function start(string $method, string $ref, ?Transaction $tx, string $msisdn, ?string $country, ?string $operator, int $amount, string $currency, ?string $name, ?string $motif = null): array
    {
        $req = RemitRequest::firstOrCreate(['partner' => $this->railName(), 'reference' => $ref], [
            'transaction_id' => $tx?->id, 'status' => 'new', 'method' => $method,
        ]);
        if ($req->partner_ref || in_array($req->status, ['pending', 'successful'], true)) {
            // Déjà envoyé : jamais deux fois (même référence chez Fincra)
            return ['status' => $req->status === 'failed' ? 'failed' : ($req->status === 'successful' ? 'successful' : 'pending'), 'external_ref' => $req->partner_ref ?: $ref, 'raw' => (array) $req->last_response];
        }

        try {
            $route = $this->corridors->resolve($msisdn, $country);
            $iso = strtoupper((string) ($route['country'] ?? $country));
            $opName = $operator ?? $route['operator'] ?? null;
            if ($method === 'deposit') {
                $code = FincraClient::operator($opName);
                if (! $code) {
                    throw new RemitException('Fincra : opérateur mobile money non reconnu pour ' . $route['phone'] . '.', 'OPERATOR', null, [], true);
                }
                $r = $this->client->charge($ref, ['phone' => $route['phone'], 'operator' => $code, 'amount' => $amount, 'currency' => $currency,
                    'name' => $name, 'email' => $tx?->initiator?->email]);
            } else {
                $code = $this->client->mobileMoneyCode($iso, $opName, $currency);
                if (! $code) {
                    throw new RemitException('Fincra : opérateur mobile money non reconnu pour ' . $route['phone'] . '.', 'OPERATOR', null, [], true);
                }
                [$first, $last] = self::splitName($name);
                $r = $this->client->payout($ref, [
                    'amount' => $amount, 'source_currency' => $currency, 'destination_currency' => $currency, 'destination' => 'mobile_money_wallet',
                    'description' => $motif ?: 'OfficePay ' . ($tx?->reference ?? $ref),
                    'beneficiary' => ['firstName' => $first, 'lastName' => $last, 'accountHolderName' => trim($first . ' ' . $last), 'type' => 'individual',
                        'country' => $iso, 'accountNumber' => $this->client->msisdn($route['phone']), 'mobileMoneyCode' => $code],
                ]);
            }

            $req->update(['partner_ref' => $r['id'] ?: $ref, 'status' => 'pending',
                'raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now()]);
            if ($tx) {
                $extra = ['fincra_' . $method => ['at' => now()->toIso8601String(), 'id' => $r['id'], 'operator' => $code, 'sent' => $r['sent'], 'response' => $r['raw']]];
                if ($method === 'deposit' && ! empty($r['message'])) {
                    // Instructions de paiement à afficher au client (ex. composer *105# pour valider)
                    $extra['payer_instructions'] = mb_substr($r['message'], 0, 300);
                }
                if ($method === 'deposit') {
                    $extra['fincra_charge_id'] = $r['charge_id'] ?? null;
                    $extra['fincra_otp_required'] = ($r['auth_model'] ?? null) === 'OTP';
                }
                $tx->forceFill(['meta' => array_merge($tx->fresh()->meta ?? [], $extra)])->save();
            }
            // Même un « successful » immédiat est relu auprès de Fincra avant de valider
            return ['status' => 'pending', 'external_ref' => $r['id'] ?: $ref, 'raw' => $r['raw']];
        } catch (RemitException $e) {
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'partner_ref' => $definitive ? null : $ref,
                'raw_status' => $e->partnerCode ? mb_substr($e->partnerCode, 0, 60) : null, 'message' => mb_substr($e->getMessage(), 0, 250),
                'last_response' => $e->response ?: null, 'last_checked_at' => now()]);
            Log::warning("Fincra : {$method} " . ($definitive ? 'refusé' : 'incertain'), ['reference' => $ref, 'error' => $e->getMessage()]);
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
        $req = RemitRequest::where('partner', $this->railName())
            ->where(fn ($q) => $q->where('partner_ref', $externalRef)->orWhere('reference', $externalRef))->first();
        if (! $req) {
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => [], 'checked' => false];
        }
        try {
            // Toujours par NOTRE référence (reference de la collecte, customerReference du versement)
            $r = $req->method === 'deposit' ? $this->client->verifyCharge($req->reference) : $this->client->payoutStatus($req->reference);
        } catch (\Throwable $e) {
            $req->update(['last_checked_at' => now(), 'message' => mb_substr('Vérification : ' . $e->getMessage(), 0, 250)]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }
        // Inconnue chez Fincra longtemps après l'envoi : jamais arrivée → échec
        if (! $r['found'] && $req->created_at?->lt(now()->subMinutes((int) config('flashpay.fincra.not_found_minutes', 2)))) {
            $r['status'] = 'failed';
            $r['failure'] = 'Opération introuvable chez Fincra';
        }
        $req->fill(['raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now(),
            'message' => $r['failure'] ? mb_substr($r['failure'], 0, 250) : $req->message])->save();

        return ['status' => $r['status'], 'external_ref' => $externalRef, 'raw' => $r['raw'], 'checked' => true];
    }
}
