<?php

namespace App\Services\Remit;

use App\Models\RemitRequest;
use App\Models\Transaction;

/**
 * Rail « terrapay » : transfert international via TerraPay (wallet mobile, compte bancaire).
 * Avant l'envoi : vérification du compte du bénéficiaire, puis devis « inverse »
 * (le bénéficiaire reçoit exactement le montant annoncé au client).
 */
class TerrapayConnector extends RemitConnector
{
    /** Types de pièce FlashPay → TerraPay. */
    protected const ID_TYPES = ['CNI' => 'nationalidcard', 'PP' => 'passport', 'PC' => 'drivinglicense', 'CS' => 'residentpermit', 'RCCM' => 'businessregistration'];

    public function __construct(protected TerrapayClient $client)
    {
    }

    public function railName(): string
    {
        return 'terrapay';
    }

    protected function send(Transaction $tx, array $remit, int $amountOut, string $currencyOut): array
    {
        $credit = self::credit($remit);
        $benName = self::fullName($remit['beneficiary']);
        $senderName = self::fullName($remit['sender']);

        if (config('flashpay.terrapay.verify_account', true)) {
            $acc = $this->client->accountStatus($credit, $benName, $senderName);
            if (! $acc['available']) {
                throw new RemitException('TerraPay : compte du bénéficiaire non disponible (' . ($acc['sub_status'] ?: $acc['status']) . ').', '6000', null, $acc['raw'], true);
            }
        }

        $senderMsisdn = (string) ($remit['sender']['phone'] ?? $tx->initiator?->phone);
        $sending = strtoupper((string) (config('flashpay.terrapay.sending_currency') ?: $tx->currency));
        $q = $this->client->quote($senderMsisdn, $credit, $amountOut, $currencyOut, $sending, $currencyOut);

        $w = WacepayRemitConnector::why($remit);
        $types = (array) config('flashpay.terrapay.types', []);
        $r = $this->client->transaction([
            'reference' => $tx->reference,
            'amount' => $amountOut,
            'currency' => $currencyOut,
            'type' => $types[$remit['business_type'] ?? 'p2p'] ?? 'inttransfer',
            'description' => $remit['note'] ?? ('FlashPay ' . strtoupper((string) ($remit['business_type'] ?? 'p2p'))),
            'sender_msisdn' => $senderMsisdn,
            'credit' => $credit,
            'sender_kyc' => self::kyc($remit['sender'], true),
            'recipient_kyc' => self::kyc($remit['beneficiary'] + ['country' => $remit['country']], false),
            'quote_id' => $q['quote_id'],
            'receiving_country' => $remit['country'],
            'purpose' => $w['reason'],
            'source_of_funds' => $w['origin_fund'],
            'relation' => $w['relation'],
        ]);
        $r['raw']['quote'] = $q['raw'];
        return $r;
    }

    public function partnerStatus(RemitRequest $req): array
    {
        return $this->client->status($req->partner_ref);
    }

    public static function credit(array $remit): array
    {
        $acc = (array) ($remit['account'] ?? []);
        return $remit['method'] === 'bank'
            ? ['method' => 'bank', 'account' => (string) $acc['account'], 'bank_name' => $acc['bank_name'] ?? null, 'swift' => $acc['swift'] ?? null,
                'branch_code' => $acc['branch'] ?? null, 'bank_code' => $acc['bank_code'] ?? null, 'account_type' => $acc['account_type'] ?? null, 'country' => $remit['country']]
            : ['method' => 'wallet', 'msisdn' => (string) $acc['msisdn'], 'provider' => $acc['provider'] ?? null];
    }

    public static function fullName(array $p): string
    {
        return ($p['type'] ?? 'P') === 'B'
            ? trim((string) ($p['business_name'] ?? ''))
            : trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    }

    /** senderKyc / recipientKyc (format GSMA). Entreprise : raison sociale + n° RCCM. */
    public static function kyc(array $p, bool $sender): array
    {
        $business = ($p['type'] ?? 'P') === 'B';
        $country = strtoupper((string) ($p['country'] ?? config('flashpay.remit.from_country', 'CG')));
        $idType = $business ? 'RCCM' : strtoupper((string) ($p['id_type'] ?? 'CNI'));
        $idNumber = $business ? ($p['registration_number'] ?? null) : ($p['id_number'] ?? null);
        $name = $business
            ? ['firstName' => (string) $p['business_name'], 'lastName' => (string) ($p['business_type'] ?? 'SARL'), 'fullName' => (string) $p['business_name']]
            : ['firstName' => (string) ($p['first_name'] ?? ''), 'lastName' => (string) ($p['last_name'] ?? ''), 'fullName' => self::fullName($p)];

        return array_filter([
            'nationality' => $business ? $country : strtoupper((string) ($p['nationality'] ?? $country)),
            'dateOfBirth' => $business ? ($p['registered_at'] ?? null) : ($p['date_of_birth'] ?? null),
            'gender' => $business ? null : ($p['gender'] ?? null),
            'idDocument' => $idNumber ? [array_filter([
                'idType' => self::ID_TYPES[$idType] ?? strtolower($idType),
                'idNumber' => (string) $idNumber,
                'expiryDate' => $business ? ($p['registration_expiry'] ?? null) : ($p['id_expiry'] ?? null),
                'issuerCountry' => $country,
            ])] : null,
            'postalAddress' => array_filter(['addressLine1' => $p['address'] ?? $p['city'] ?? null, 'city' => $p['city'] ?? null, 'country' => $country]),
            'subjectName' => $name,
            'emailAddress' => $p['email'] ?? null,
            'occupation' => $sender && ! $business ? ($p['occupation'] ?? null) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
