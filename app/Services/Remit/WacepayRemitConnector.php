<?php

namespace App\Services\Remit;

use App\Models\RemitRequest;
use App\Models\Transaction;

/**
 * Rail « wacepay_remit » : transfert international via l'API WacePay v2
 * (wallet mobile, compte bancaire, retrait cash). Montant envoyé en devise
 * d'envoi (XAF) : WacePay applique son change et paie la devise du pays.
 */
class WacepayRemitConnector extends RemitConnector
{
    public function __construct(protected WacepayRemitClient $client)
    {
    }

    public function railName(): string
    {
        return 'wacepay_remit';
    }

    protected function send(Transaction $tx, array $remit, int $amountOut, string $currencyOut): array
    {
        $c = $this->client;
        $fromCountry = strtoupper((string) ($remit['sender']['country'] ?? config('flashpay.remit.from_country', 'CG')));
        $country = strtoupper((string) $remit['country']);
        $payCurrency = strtoupper((string) ($remit['currency'] ?? $currencyOut));
        $why = self::why($remit);

        $sender = $c->senderCode($remit['sender'], $tx->initiated_by);
        $beneficiary = $c->beneficiaryCode($remit['beneficiary'] + ['country' => $country], $sender, $tx->initiated_by);
        $service = (array) ($remit['service'] ?? []);
        $payer = $c->payerCode($country, $payCurrency, $service);
        $acc = (array) ($remit['account'] ?? []);

        return match ($remit['method']) {
            'bank' => $c->createBank($payer, (int) $tx->amount, $tx->currency, $sender, $beneficiary, [
                'account' => (string) $acc['account'], 'name' => (string) ($acc['bank_name'] ?? ''), 'id' => (int) ($acc['bank_id'] ?? 0),
                'branch' => $acc['branch'] ?? null, 'swift' => $acc['swift'] ?? '',
            ], $fromCountry, $why, (string) ($remit['note'] ?? $tx->reference)),
            'cash' => $c->createCash([
                'collection_code' => (string) ($acc['collection_code'] ?? ''), 'to_country' => $country, 'to_city' => (string) ($acc['city'] ?? ''),
                'to_currency' => $payCurrency, 'amount' => (int) $tx->amount, 'service' => (string) ($service['name'] ?? $service['code'] ?? ''),
                'question' => (string) ($acc['question'] ?? ''), 'answer' => (string) ($acc['answer'] ?? ''),
                'sender_code' => $sender, 'beneficiary_code' => $beneficiary, 'from_currency' => $tx->currency,
                'mobile' => (string) ($remit['beneficiary']['phone'] ?? ''), 'from_country' => $fromCountry,
            ], $why),
            default => $c->createWallet($payer, (int) $tx->amount, $tx->currency, $sender, $beneficiary, (string) $acc['msisdn'], $fromCountry, $why),
        };
    }

    public function partnerStatus(RemitRequest $req): array
    {
        $r = $this->client->status($req->partner_ref);
        // « WAITING CONFIRMATION » : OfficePay confirme lui-même la transaction (une fois)
        if (strtoupper((string) $r['raw_status']) === 'WAITING CONFIRMATION' && config('flashpay.wacepay_remit.auto_confirm', true)) {
            try {
                $this->client->confirm($req->partner_ref);
                $r = $this->client->status($req->partner_ref);
            } catch (RemitException $e) {
                if ($e->partnerCode !== '1016') { // 1016 = déjà confirmée
                    $req->update(['message' => mb_substr('Confirmation : ' . $e->getMessage(), 0, 250)]);
                }
            }
        }
        return $r;
    }

    public static function why(array $remit): array
    {
        $w = (array) ($remit['why'] ?? []);
        $b2 = str_starts_with((string) ($remit['business_type'] ?? 'p2p'), 'b');
        return [
            'origin_fund' => (string) ($w['origin_fund'] ?? ($b2 ? 'Business Income' : 'Salary')),
            'reason' => (string) ($w['reason'] ?? 'Family Maintainance'),
            'relation' => (string) ($w['relation'] ?? ($b2 ? 'Colleague' : 'Friend')),
        ];
    }
}
