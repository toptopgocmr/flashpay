<?php

namespace App\Services\Remit;

/**
 * Nom de la banque à partir du code BIC / SWIFT ou de l'IBAN, pour pré-remplir le
 * formulaire « Compte bancaire » de l'app (le client peut toujours corriger).
 *
 *  - BIC : AAAA BB CC (DDD) → AAAA = établissement, BB = pays. Table des établissements courants.
 *  - IBAN France / Monaco : code banque (5 chiffres après « FR76 ») → banque et BIC.
 *  - Liste des banques du partenaire (Digitwace) quand elle existe : entrée correspondante (bank_id).
 */
class BankDirectory
{
    /** Établissement (4 lettres du BIC, ou 8 caractères quand le pays change le nom). */
    public const BIC = [
        // France
        'BNPA' => 'BNP Paribas', 'SOGE' => 'Société Générale', 'CRLY' => 'LCL (Crédit Lyonnais)', 'AGRI' => 'Crédit Agricole',
        'CEPA' => 'Caisse d\'Épargne', 'CCBP' => 'Banque Populaire', 'BRED' => 'BRED Banque Populaire', 'PSST' => 'La Banque Postale',
        'BOUS' => 'BoursoBank (Boursorama)', 'FPEL' => 'Nickel (Financière des Paiements Électroniques)', 'FTNO' => 'Fortuneo',
        'CCFR' => 'HSBC Continental Europe', 'AXAB' => 'AXA Banque', 'BFBK' => 'BforBank', 'CMBR' => 'Crédit Mutuel de Bretagne (Arkéa)',
        'CMCIFRPP' => 'CIC', 'CMCI' => 'Crédit Mutuel', 'QNTO' => 'Qonto', 'SMOE' => 'Shine', 'CRES' => 'Crédit Coopératif',
        // Néobanques et banques européennes
        'REVO' => 'Revolut', 'NTSB' => 'N26', 'TRWI' => 'Wise', 'GEBA' => 'BNP Paribas Fortis', 'KRED' => 'KBC', 'BBRU' => 'ING Belgique',
        'GKCC' => 'Belfius', 'INGB' => 'ING', 'DEUT' => 'Deutsche Bank', 'COBA' => 'Commerzbank', 'BBVA' => 'BBVA', 'CAIX' => 'CaixaBank',
        'BSCH' => 'Banco Santander', 'UNCR' => 'UniCredit', 'BCIT' => 'Intesa Sanpaolo', 'BARC' => 'Barclays', 'HBUK' => 'HSBC UK',
        'LOYD' => 'Lloyds Bank', 'NWBK' => 'NatWest', 'UBSW' => 'UBS', 'POFI' => 'PostFinance', 'BCEE' => 'Spuerkeess (BCEE)',
        // Afrique (UEMOA / CEMAC)
        'ECOC' => 'Ecobank', 'UNAF' => 'United Bank for Africa (UBA)', 'SGCI' => 'SGBCI (Société Générale Côte d\'Ivoire)', 'SGSN' => 'Société Générale Sénégal',
        'SGCM' => 'Société Générale Cameroun', 'BICI' => 'BICICI', 'AFRI' => 'Afriland First Bank', 'CBAO' => 'CBAO Groupe Attijariwafa',
        'ORAG' => 'Orabank', 'BOAB' => 'Bank of Africa', 'CORI' => 'Coris Bank International', 'BGFI' => 'BGFIBank', 'LCDC' => 'LCB Bank',
    ];

    /** France / Monaco : code banque de l'IBAN → [nom, BIC]. */
    public const FR_BANK_CODES = [
        '30004' => ['BNP Paribas', 'BNPAFRPP'], '30003' => ['Société Générale', 'SOGEFRPP'], '30002' => ['LCL (Crédit Lyonnais)', 'CRLYFRPP'],
        '20041' => ['La Banque Postale', 'PSSTFRPP'], '16598' => ['Nickel (Financière des Paiements Électroniques)', 'FPELFR21'],
        '40618' => ['BoursoBank (Boursorama)', 'BOUSFRPP'], '30066' => ['CIC', 'CMCIFRPP'], '10278' => ['Crédit Mutuel', 'CMCIFR2A'],
        '30056' => ['HSBC Continental Europe', 'CCFRFRPP'], '14518' => ['Fortuneo', 'FTNOFRP1'], '10107' => ['BRED Banque Populaire', 'BREDFRPP'],
        '42559' => ['Crédit Coopératif', 'CCOPFRPP'], '16958' => ['Qonto', 'QNTOFRP1'],
    ];

    public static function normalizeBic(?string $bic): ?string
    {
        $b = strtoupper(preg_replace('/\s+/', '', (string) $bic));
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $b) ? $b : null;
    }

    /** @return array{bank_name:?string, swift:?string, country:?string, source:?string} */
    public function lookup(?string $iban, ?string $bic): array
    {
        $out = ['bank_name' => null, 'swift' => self::normalizeBic($bic), 'country' => null, 'source' => null];
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban));

        if ($out['swift']) {
            $out['country'] = substr($out['swift'], 4, 2);
            $name = self::BIC[substr($out['swift'], 0, 8)] ?? self::BIC[substr($out['swift'], 0, 4)] ?? null;
            if ($name) {
                $out['bank_name'] = $name;
                $out['source'] = 'bic';
            }
        }
        if (preg_match('/^(FR|MC)\d{2}(\d{5})/', $iban, $m) && isset(self::FR_BANK_CODES[$m[2]])) {
            [$name, $swift] = self::FR_BANK_CODES[$m[2]];
            $out['country'] ??= $m[1];
            if (! $out['bank_name']) {
                $out['bank_name'] = $name;
                $out['source'] = 'iban';
            }
            $out['swift'] ??= $swift;
        }
        if (! $out['country'] && preg_match('/^[A-Z]{2}/', $iban)) {
            $out['country'] = substr($iban, 0, 2);
        }
        return $out;
    }

    /** Entrée de la liste du partenaire (WacePay : [{id, name, code}]) qui correspond au BIC ou au nom. */
    public static function matchPartnerList(array $banks, ?string $bic, ?string $name): ?array
    {
        $bic8 = $bic ? substr($bic, 0, 8) : null;
        $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower(\Illuminate\Support\Str::ascii((string) $s)));
        foreach ($banks as $b) {
            $code = strtoupper((string) ($b['code'] ?? ''));
            if ($bic8 && $code !== '' && ($code === $bic || str_starts_with($code, $bic8))) {
                return $b;
            }
        }
        if ($name) {
            $short = $norm(preg_replace('/\s*\(.*\)$/', '', $name));
            foreach ($banks as $b) {
                $n = $norm($b['name'] ?? '');
                if ($short !== '' && $n !== '' && (str_contains($n, $short) || str_contains($short, $n))) {
                    return $b;
                }
            }
        }
        return null;
    }
}
