<?php

namespace App\Services\Remit;

use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Services\Connectors\Contracts\PaymentRailConnector;
use Illuminate\Support\Facades\Log;

/**
 * Base des rails de transfert international (versement uniquement).
 * Les détails du transfert (type P2P/P2B/B2P/B2B, moyen de réception, KYC
 * expéditeur / bénéficiaire, motif…) sont dans transaction.meta.remit,
 * préparés par InternationalTransferService.
 *
 * Asynchrone : l'envoi revient « pending » ; le statut final est lu par
 * `php artisan remit:sync` (chaque minute) et TOUJOURS confirmé par l'API
 * du partenaire avant de valider ou de rembourser le client.
 */
abstract class RemitConnector implements PaymentRailConnector
{
    abstract public function railName(): string;

    /** Envoie le transfert chez le partenaire. @return array{reference:string, status:string, raw_status?:?string, raw:array, sent?:array, fees?:?float} */
    abstract protected function send(Transaction $tx, array $remit, int $amountOut, string $currencyOut): array;

    /** Statut chez le partenaire. @return array{status:string, raw_status:?string, raw:array} */
    abstract public function partnerStatus(RemitRequest $req): array;

    /** Référence partenaire connue d'avance (sinon null) : permet de vérifier une issue incertaine. */
    public function partnerRefFor(string $reference): ?string
    {
        return null;
    }

    public function collect(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        throw new RemitException('Collecte non proposée par ' . $this->railName() . ' (versement international uniquement).', null, null, [], true);
    }

    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array
    {
        $tx = Transaction::where('reference', $reference)->first();
        $remit = (array) ($tx?->meta['remit'] ?? []);
        $req = RemitRequest::firstOrCreate(['partner' => $this->railName(), 'reference' => $reference], [
            'transaction_id' => $tx?->id, 'partner' => $this->railName(), 'status' => 'new',
            'method' => $remit['method'] ?? null, 'business_type' => $remit['business_type'] ?? null,
        ]);
        if ($req->partner_ref) {
            // Déjà envoyé (rejeu) : on ne renvoie jamais deux fois
            return ['status' => $req->status === 'failed' ? 'failed' : 'pending', 'external_ref' => $req->partner_ref, 'raw' => (array) $req->last_response];
        }

        try {
            if (! $tx || ! $remit) {
                throw new RemitException('Détails du transfert international introuvables.', null, null, [], true);
            }
            $r = $this->send($tx, $remit, $amountMinor, $currency);
            $req->update(['partner_ref' => $r['reference'], 'status' => $r['status'] === 'successful' ? 'successful' : 'pending',
                'raw_status' => $r['raw_status'] ?? null, 'last_response' => $r['raw'], 'last_checked_at' => now()]);
            $tx->forceFill(['meta' => array_merge($tx->meta ?? [], [$this->railName() . '_payout' => [
                'at' => now()->toIso8601String(), 'reference' => $r['reference'], 'sent' => $this->redact($r['sent'] ?? []),
                'response' => $r['raw'], 'partner_fees' => $r['fees'] ?? null,
            ]])])->save();

            return ['status' => $r['status'] === 'successful' ? 'successful' : 'pending', 'external_ref' => $r['reference'], 'raw' => $r['raw']];
        } catch (RemitException $e) {
            $definitive = $e->isDefinitive();
            $req->update(['status' => $definitive ? 'failed' : 'pending', 'message' => mb_substr($e->getMessage(), 0, 250),
                'last_response' => $e->response ?: null, 'last_checked_at' => now(),
                'partner_ref' => $definitive ? null : $this->partnerRefFor($reference)]);
            Log::warning($this->railName() . ' : transfert ' . ($definitive ? 'refusé' : 'incertain'), ['reference' => $reference, 'error' => $e->getMessage()]);

            return ['status' => $definitive ? 'failed' : 'pending', 'external_ref' => $reference, 'raw' => ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            $req->update(['status' => 'pending', 'message' => mb_substr($e->getMessage(), 0, 250), 'last_checked_at' => now(), 'partner_ref' => $this->partnerRefFor($reference)]);
            return ['status' => 'pending', 'external_ref' => $reference, 'raw' => ['error' => $e->getMessage()]];
        }
    }

    public function checkStatus(string $externalRef): array
    {
        $req = RemitRequest::where('partner_ref', $externalRef)->orWhere('reference', $externalRef)->first();
        if (! $req || ! $req->partner_ref) {
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => [], 'checked' => false];
        }
        try {
            $r = $this->partnerStatus($req);
        } catch (\Throwable $e) {
            $req->update(['last_checked_at' => now(), 'message' => mb_substr('Vérification : ' . $e->getMessage(), 0, 250)]);
            return ['status' => 'unknown', 'external_ref' => $externalRef, 'raw' => ['error' => $e->getMessage()], 'checked' => false];
        }
        $req->update(['raw_status' => $r['raw_status'], 'last_response' => $r['raw'], 'last_checked_at' => now()]);
        return ['status' => $r['status'], 'external_ref' => $externalRef, 'raw' => $r['raw'], 'checked' => true];
    }

    /** Pas de réponse secrète (retrait cash) ni de pièce d'identité complète dans les journaux. */
    protected function redact(array $sent): array
    {
        array_walk_recursive($sent, function (&$v, $k) {
            if (in_array(strtolower((string) $k), ['response', 'idnumber', 'idNumber'], true) && is_scalar($v) && $v !== '') {
                $v = str_repeat('•', max(0, mb_strlen((string) $v) - 3)) . mb_substr((string) $v, -3);
            }
        });
        return $sent;
    }
}
