<?php

namespace App\Services\Remit;

use App\Models\RemitRequest;
use App\Models\Transaction;

/**
 * Rail « pawapay » : transfert international vers un wallet mobile money via pawaPay
 * (API Remittances, ou Payouts si PAWAPAY_MODE=payout). Le bénéficiaire reçoit le
 * montant en devise locale, prélevé sur le wallet FlashPay chez pawaPay.
 */
class PawapayConnector extends RemitConnector
{
    /** Types de pièce FlashPay → pawaPay. */
    protected const ID_TYPES = ['CNI' => 'NATIONAL_ID', 'PP' => 'PASSPORT', 'PC' => 'DRIVERS_LICENSE', 'CS' => 'RESIDENCE_PERMIT', 'RCCM' => 'BUSINESS_REGISTRATION'];

    /** Motifs / origines (WacePay ou FlashPay) → valeurs pawaPay. */
    protected const PURPOSES = [
        'family' => 'FAMILY_SUPPORT', 'medical' => 'MEDICAL_EXPENSES', 'education' => 'EDUCATION_SUPPORT', 'tuition' => 'TUITION_FEES',
        'gift' => 'GIFT_AND_OTHER_DONATIONS', 'donation' => 'GIFT_AND_OTHER_DONATIONS', 'rent' => 'REAL_ESTATE', 'property' => 'REAL_ESTATE',
        'salary' => 'SALARY', 'employee' => 'SALARY', 'saving' => 'SAVINGS', 'tax' => 'TAXES', 'debt' => 'DEBT_SETTLEMENT', 'home' => 'HOME_IMPROVEMENT',
    ];
    protected const SOURCES = ['salary' => 'SALARY', 'saving' => 'SAVINGS', 'lottery' => 'LOTTERY', 'loan' => 'LOAN', 'business' => 'BUSINESS_INCOME', 'gift' => 'GIFT', 'settlement' => 'BUSINESS_INCOME'];

    public function __construct(protected PawapayClient $client)
    {
    }

    public function railName(): string
    {
        return 'pawapay';
    }

    protected function send(Transaction $tx, array $remit, int $amountOut, string $currencyOut): array
    {
        $acc = (array) ($remit['account'] ?? []);
        $provider = $acc['provider'] ?? null;
        $phone = preg_replace('/\D/', '', (string) ($acc['msisdn'] ?? ''));
        if (! $provider) {
            $pred = $this->client->predictProvider($phone);
            $provider = $pred['provider'];
            $phone = $pred['phone'];
        }
        $s = (array) $remit['sender'];
        $b = (array) $remit['beneficiary'];
        $business = ($b['type'] ?? 'P') === 'B';
        $w = WacepayRemitConnector::why($remit);
        $fx = (float) ($tx->meta['fx']['rate'] ?? ($tx->amount > 0 ? $amountOut / $tx->amount : 1));
        $senderIso = strtoupper((string) ($s['country'] ?? config('flashpay.remit.from_country', 'CG')));

        return $this->client->transfer(self::uuidFor($tx->reference), [
            'amount' => $amountOut,
            'currency' => $currencyOut,
            'phone' => $phone,
            'provider' => $provider,
            'reference' => $tx->reference,
            'message' => $remit['note'] ?? 'FlashPay',
            'recipient_first_name' => $business ? (string) $b['business_name'] : (string) $b['first_name'],
            'recipient_last_name' => $business ? (string) ($b['business_type'] ?? 'SARL') : (string) $b['last_name'],
            'original_amount' => (int) $tx->amount,
            'original_currency' => $tx->currency,
            'fx_rate' => round($fx, 6),
            'sender_fees' => (int) $tx->fee,
            'purpose' => self::match($w['reason'], self::PURPOSES, ($remit['business_type'] ?? 'p2p') === 'b2p' ? 'SALARY' : 'PERSONAL_TRANSFER'),
            'source_of_funds' => self::match($w['origin_fund'], self::SOURCES, 'OTHER'),
            'sender' => [
                'first_name' => (string) (($s['type'] ?? 'P') === 'B' ? $s['business_name'] : $s['first_name']),
                'last_name' => (string) (($s['type'] ?? 'P') === 'B' ? ($s['business_type'] ?? 'SARL') : $s['last_name']),
                'nationality' => PawapayClient::iso3($s['nationality'] ?? $senderIso) ?? 'COG',
                'phone' => preg_replace('/\D/', '', (string) ($s['phone'] ?? $tx->initiator?->phone)),
                'address' => (string) ($s['address'] ?? $s['city'] ?? 'N/A'),
                'city' => (string) ($s['city'] ?? config('flashpay.wacepay_remit.default_address', 'Brazzaville')),
                'country' => PawapayClient::iso3($senderIso) ?? 'COG',
                'id_type' => (($s['type'] ?? 'P') === 'B') ? self::ID_TYPES['RCCM'] : (self::ID_TYPES[strtoupper((string) ($s['id_type'] ?? 'CNI'))] ?? 'NATIONAL_ID'),
                'id_number' => (string) ((($s['type'] ?? 'P') === 'B') ? ($s['registration_number'] ?? '') : ($s['id_number'] ?? '')),
                'date_of_birth' => $s['date_of_birth'] ?? null,
                'gender' => $s['gender'] ?? null,
                'occupation' => $s['occupation'] ?? null,
                'relationship' => $w['relation'],
            ],
        ]);
    }

    /** Identifiant pawaPay déterministe : un renvoi éventuel est ignoré (DUPLICATE_IGNORED) et le statut reste vérifiable. */
    public static function uuidFor(string $reference): string
    {
        $h = md5('flashpay-pawapay:' . $reference);
        return sprintf('%s-%s-4%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex(8 | (hexdec($h[16]) & 3)), substr($h, 17, 3), substr($h, 20, 12));
    }

    public function partnerRefFor(string $reference): ?string
    {
        return self::uuidFor($reference);
    }

    public function partnerStatus(RemitRequest $req): array
    {
        $r = $this->client->status($req->partner_ref);
        // Inconnu chez pawaPay 15 min après un envoi incertain : il n'est jamais arrivé → échec (remboursement)
        if ($r['raw_status'] === 'NOT_FOUND' && $req->created_at && $req->created_at->lt(now()->subMinutes(15))) {
            $r['status'] = 'failed';
        }
        return $r;
    }

    protected static function match(string $value, array $map, string $default): string
    {
        $v = strtolower($value);
        foreach ($map as $needle => $code) {
            if (str_contains($v, $needle)) {
                return $code;
            }
        }
        return in_array(strtoupper($value), array_unique(array_values($map)), true) ? strtoupper($value) : $default;
    }
}
