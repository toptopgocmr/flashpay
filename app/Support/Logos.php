<?php

namespace App\Support;

use App\Services\Remit\BankDirectory;
use Illuminate\Support\Str;

/**
 * Logos des opérateurs mobile money et des banques (transferts internationaux).
 *
 * Renvoie l'URL d'une image (icône du site officiel) ou null. L'app affiche :
 * logo embarqué (MTN, Airtel, Orange, Moov, Africell, Visa…) → cette image →
 * à défaut, un badge avec les initiales : chaque opérateur / banque a donc un visuel.
 */
class Logos
{
    /** Mot-clé (minuscules, sans accents) → site de l'opérateur. Le premier qui correspond l'emporte. */
    public const OPERATORS = [
        'wave' => 'wave.com',
        'orange' => 'orange.com',
        'mtn' => 'mtn.com', 'momo' => 'mtn.com',
        'airtel' => 'airtel.africa',
        'moov' => 'moov-africa.ci', 'flooz' => 'moov-africa.ci',
        'free money' => 'yas.sn', 'free' => 'yas.sn', 'yas' => 'yas.sn', 'mixx' => 'yas.sn',
        'togocom' => 'togocom.tg', 't-money' => 'togocom.tg', 'tmoney' => 'togocom.tg',
        'celtiis' => 'celtiis.bj',
        'vodacom' => 'vodacom.cd', 'm-pesa' => 'vodacom.cd', 'mpesa' => 'vodacom.cd',
        'safaricom' => 'safaricom.co.ke',
        'tigo' => 'tigo.com',
        'africell' => 'africell.com', 'afrimoney' => 'africell.com',
        'camtel' => 'camtel.cm', 'nexttel' => 'nexttel.cm',
        'telma' => 'telma.mg', 'mvola' => 'telma.mg',
        'ecocash' => 'econet.co.zw',
        'opay' => 'opayweb.com', 'palmpay' => 'palmpay.com', 'moniepoint' => 'moniepoint.com',
        'chipper' => 'chippercash.com',
        'maishapay' => 'maishapay.online',
    ];

    /** Banques citées par leur nom dans les listes partenaires (en plus des BIC de BankDirectory). */
    public const BANKS = [
        'united bank for africa' => 'ubagroup.com', 'uba' => 'ubagroup.com',
        'ecobank' => 'ecobank.com', 'bank of africa' => 'bank-of-africa.net', 'boa' => 'bank-of-africa.net',
        'societe generale' => 'societegenerale.com', 'sgbci' => 'societegenerale.ci', 'sgbs' => 'societegenerale.sn',
        'banque atlantique' => 'banqueatlantique.net', 'coris' => 'corisbank.com', 'orabank' => 'orabank.net',
        'bgfi' => 'bgfi.com', 'afriland' => 'afrilandfirstbank.com', 'attijariwafa' => 'attijariwafabank.com', 'cbao' => 'cbao.sn',
        'nsia' => 'groupensia.com', 'bridge bank' => 'bridgebankgroup.com', 'bicici' => 'bicici.com', 'bsic' => 'bsicbank.com',
        'standard chartered' => 'sc.com', 'citibank' => 'citigroup.com', 'citi' => 'citigroup.com',
        'access bank' => 'accessbankplc.com', 'zenith' => 'zenithbank.com', 'guaranty trust' => 'gtbank.com', 'gtbank' => 'gtbank.com',
        'first bank' => 'firstbanknigeria.com', 'stanbic' => 'stanbicbank.com', 'equity bank' => 'equitygroupholdings.com',
        'bnp' => 'bnpparibas.com', 'credit agricole' => 'credit-agricole.fr', 'lcl' => 'lcl.fr', 'banque postale' => 'labanquepostale.fr',
        'caisse d\'epargne' => 'caisse-epargne.fr', 'caisse depargne' => 'caisse-epargne.fr', 'banque populaire' => 'banquepopulaire.fr',
        'cic' => 'cic.fr', 'credit mutuel' => 'creditmutuel.fr', 'boursorama' => 'boursobank.com', 'boursobank' => 'boursobank.com',
        'nickel' => 'nickel.eu', 'financiere des paiements' => 'nickel.eu', 'revolut' => 'revolut.com', 'wise' => 'wise.com', 'n26' => 'n26.com',
        'hsbc' => 'hsbc.com', 'barclays' => 'barclays.co.uk', 'ing' => 'ing.com', 'santander' => 'santander.com', 'deutsche bank' => 'deutsche-bank.de',
        'qonto' => 'qonto.com', 'shine' => 'shine.fr', 'fortuneo' => 'fortuneo.fr', 'bred' => 'bred.fr', 'axa' => 'axa.fr',
    ];

    public static function url(string $domain): string
    {
        return 'https://www.google.com/s2/favicons?sz=128&domain=' . $domain;
    }

    public static function operator(?string $name): ?string
    {
        $n = self::norm($name);
        if ($n === '') {
            return null;
        }
        foreach (self::OPERATORS as $k => $domain) {
            if (preg_match('/(^|[^a-z])' . preg_quote($k, '/') . '([^a-z]|$)/', $n)) {
                return self::url($domain);
            }
        }
        return null;
    }

    public static function bank(?string $name, ?string $bic = null, ?string $iban = null): ?string
    {
        if ($url = BankDirectory::logo($bic, $iban, $name)) {
            return $url;
        }
        $n = self::norm($name);
        if ($n === '') {
            return null;
        }
        foreach (self::BANKS as $k => $domain) {
            if (preg_match('/(^|[^a-z])' . preg_quote($k, '/') . '([^a-z]|$)/', $n)) {
                return self::url($domain);
            }
        }
        // Certaines listes donnent un opérateur comme « banque » (ex. Orange Bank, Wave)
        return self::operator($name);
    }

    protected static function norm(?string $s): string
    {
        return trim(Str::lower(Str::ascii((string) $s)));
    }
}
