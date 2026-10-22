<?php

namespace App\Services\Fincra;

use App\Models\RemitRequest;
use App\Models\Transaction;
use App\Services\Remit\RemitConnector;
use App\Services\Remit\RemitException;
use App\Services\Remit\WacepayRemitConnector;

/**
 * Rail « fincra_remit » : transfert international (remittance) via Fincra Payouts.
 *
 *  - wallet → paymentDestination mobile_money_wallet (mobileMoneyCode lu sur /core/banks) ;
 *  - bank   → paymentDestination bank_account (bankCode lu sur /core/banks).
 *
 * Le bénéficiaire reçoit le montant en devise locale. Le solde Fincra débité est en devise
 * FINCRA_REMIT_SOURCE_CURRENCY (vide = même devise : aucun change chez Fincra) ; sinon un devis
 * /quotes/generate (action « receive », valable 30 s) fixe le montant à recevoir et sa référence
 * accompagne le versement.
 *
 * Référence Fincra (customerReference) = référence OfficePay : un renvoi est refusé par Fincra
 * (DUPLICATE_CUSTOMER_REFERENCE) et le statut reste toujours vérifiable.
 */
class FincraRemitConnector extends RemitConnector
{
    /** Schéma de paiement requis pour un virement en devise étrangère. */
    protected const SCHEMES = ['EUR' => 'sepa', 'GBP' => 'fps', 'USD' => 'ach'];

    public function __construct(protected FincraClient $client)
    {
    }

    public function railName(): string
    {
        return 'fincra_remit';
    }

    protected function send(Transaction $tx, array $remit, int $amountOut, string $currencyOut): array
    {
        $iso = strtoupper((string) ($remit['country'] ?? ''));
        $method = $remit['method'] ?? 'wallet';
        if (! in_array($method, ['wallet', 'bank'], true)) {
            throw new RemitException('Fincra : mode de réception non proposé (' . $method . ').', 'METHOD', null, [], true);
        }
        $acc = (array) ($remit['account'] ?? []);
        $b = (array) ($remit['beneficiary'] ?? []);
        $corporate = ($b['type'] ?? 'P') === 'B';
        $destination = $method === 'bank' ? 'bank_account' : 'mobile_money_wallet';
        $currencyOut = strtoupper($currencyOut);

        $beneficiary = [
            'type' => $corporate ? 'corporate' : 'individual',
            'country' => $iso,
            'firstName' => $corporate ? null : (string) ($b['first_name'] ?? ''),
            'lastName' => $corporate ? null : (string) ($b['last_name'] ?? ''),
            'accountHolderName' => $corporate ? (string) ($b['business_name'] ?? '') : trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')),
            'email' => $b['email'] ?? null,
            'phone' => ! empty($b['phone']) ? preg_replace('/\D/', '', (string) $b['phone']) : null,
            'address' => array_filter(['country' => $iso, 'city' => $b['city'] ?? null, 'street' => $b['address'] ?? null, 'zip' => $b['postal_code'] ?? null]) ?: null,
        ];
        if ($method === 'wallet') {
            $code = ($acc['provider'] ?? null) ?: $this->client->mobileMoneyCode($iso, $remit['service']['name'] ?? null, $currencyOut);
            if (! $code) {
                // Opérateur non choisi : déduit du nom de service, sinon premier opérateur du pays
                $list = $this->client->institutions($iso, 'mobile_money_wallet', $currencyOut);
                $code = count($list) === 1 ? $list[0]['code'] : null;
            }
            if (! $code) {
                throw new RemitException('Fincra : choisissez l\'opérateur mobile money du bénéficiaire.', 'OPERATOR', null, [], true);
            }
            $beneficiary['accountNumber'] = $this->client->msisdn((string) ($acc['msisdn'] ?? ''));
            $beneficiary['mobileMoneyCode'] = $code;
        } else {
            $beneficiary['accountNumber'] = (string) ($acc['account'] ?? '');
            $beneficiary['bankCode'] = $this->bankCode($iso, $acc, $currencyOut);
        }

        $source = (string) config('flashpay.fincra.remit_source_currency', '') ?: $currencyOut;
        $scheme = $method === 'bank' ? (self::SCHEMES[$currencyOut] ?? null) : null;
        $quote = null;
        if (strtoupper($source) !== $currencyOut) {
            $quote = $this->client->quote($source, $currencyOut, $amountOut, $destination, $beneficiary['type'], 'receive', $scheme);
        }
        $w = WacepayRemitConnector::why($remit);
        $r = $this->client->payout($tx->reference, [
            'amount' => $quote ? ($quote['source_amount'] ?? $amountOut) : $amountOut,
            'source_currency' => $source,
            'destination_currency' => $currencyOut,
            'destination' => $destination,
            'quote_reference' => $quote['reference'] ?? null,
            'payment_scheme' => $scheme,
            'description' => mb_substr(trim(($remit['note'] ?? '') ?: ('OfficePay · ' . $w['reason'])), 0, 100),
            'beneficiary' => $beneficiary,
        ]);

        return ['reference' => $r['id'] ?: $tx->reference, 'status' => 'pending', 'raw_status' => $r['raw_status'], 'raw' => $r['raw'],
            'sent' => $r['sent'] + ($quote ? ['quote' => array_diff_key($quote, ['raw' => 1])] : []), 'fees' => $r['fee'] ?? $quote['fee'] ?? null];
    }

    /** Code banque Fincra : banque choisie dans l'app (id de /core/banks), sinon code / nom saisi. */
    protected function bankCode(string $iso, array $acc, string $currency): string
    {
        try {
            $banks = $this->client->institutions($iso, 'bank_account', $currency);
        } catch (\Throwable) {
            $banks = [];
        }
        foreach ($banks as $bk) {
            if ((! empty($acc['bank_id']) && (string) $bk['id'] === (string) $acc['bank_id'])
                || (! empty($acc['bank_name']) && strcasecmp($bk['name'], (string) $acc['bank_name']) === 0)) {
                return $bk['code'];
            }
        }
        $code = (string) ($acc['bank_code'] ?? $acc['swift'] ?? '');
        if ($code === '') {
            throw new RemitException('Fincra : banque du bénéficiaire introuvable, choisissez-la dans la liste.', 'BANK', null, [], true);
        }
        return $code;
    }

    /** customerReference = référence OfficePay : une issue incertaine reste vérifiable. */
    public function partnerRefFor(string $reference): ?string
    {
        return $reference;
    }

    public function partnerStatus(RemitRequest $req): array
    {
        $r = $this->client->payoutStatus($req->reference);
        // Inconnu chez Fincra après un envoi incertain : il n'est jamais arrivé → échec (remboursement)
        if (! $r['found'] && $req->created_at && $req->created_at->lt(now()->subMinutes(max(2, (int) config('flashpay.fincra.not_found_minutes', 2))))) {
            $r['status'] = 'failed';
            $r['raw_status'] = 'NOT_FOUND';
        }
        if ($r['failure']) {
            $req->message = mb_substr($r['failure'], 0, 250);
        }
        return $r;
    }
}
