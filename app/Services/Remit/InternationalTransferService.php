<?php

namespace App\Services\Remit;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\FeeService;
use App\Services\Payments\FxService;
use App\Services\Peex\PeexException;
use App\Services\SwitchService;
use Illuminate\Support\Facades\Cache;

/**
 * Transfert international depuis le wallet FlashPay (app mobile client).
 *
 *   P2P  particulier → particulier      B2P  entreprise → particulier (salaires, prestataires)
 *   P2B  particulier → entreprise       B2B  entreprise → entreprise (fournisseurs, factures)
 *
 * Réception : wallet mobile money, compte bancaire, retrait cash (WacePay).
 * Partenaire choisi par pays (REMIT_PARTNERS) : WacePay API v2 ou TerraPay.
 *
 * Toutes les vérifications ont lieu AVANT le débit du wallet : partenaire
 * configuré, solde partenaire, KYC, code expéditeur / bénéficiaire (WacePay),
 * compte du bénéficiaire (TerraPay). Échec du versement → wallet remboursé
 * (montant + frais) par le Switch.
 */
class InternationalTransferService
{
    public const BUSINESS_TYPES = [
        'p2p' => ['label' => 'Particulier → Particulier', 'short' => 'P2P', 'sender' => 'P', 'beneficiary' => 'P', 'hint' => 'Famille, amis, aide personnelle'],
        'p2b' => ['label' => 'Particulier → Entreprise', 'short' => 'P2B', 'sender' => 'P', 'beneficiary' => 'B', 'hint' => 'Frais de scolarité, factures, achats'],
        'b2p' => ['label' => 'Entreprise → Particulier', 'short' => 'B2P', 'sender' => 'B', 'beneficiary' => 'P', 'hint' => 'Salaires, prestataires, remboursements'],
        'b2b' => ['label' => 'Entreprise → Entreprise', 'short' => 'B2B', 'sender' => 'B', 'beneficiary' => 'B', 'hint' => 'Fournisseurs, factures, règlements'],
    ];

    public const METHODS = ['wallet' => 'Mobile money', 'bank' => 'Compte bancaire', 'cash' => 'Retrait en espèces'];
    public const ID_TYPES = ['CNI' => 'Carte nationale d\'identité', 'PP' => 'Passeport', 'PC' => 'Permis de conduire', 'CS' => 'Carte de séjour'];
    public const COMPANY_TYPES = ['SARL', 'SARLU', 'SAS', 'SASU', 'SA', 'EI', 'GIE', 'ONG', 'Association'];

    /**
     * Listes de secours (si le partenaire ne les fournit pas) — valeurs au format WacePay
     * (Digitwace : relation, origine des fonds, motif selon P2P / P2B / B2P / B2B).
     */
    protected const DEFAULT_LISTS = [
        'p' => [
            'relations' => ['Family' => 'Famille', 'Father' => 'Père', 'Mother' => 'Mère', 'Parent' => 'Parent', 'Brother' => 'Frère', 'Sister' => 'Sœur',
                'Son' => 'Fils', 'Daughter' => 'Fille', 'Spouse' => 'Conjoint(e)', 'Uncle' => 'Oncle', 'Aunt' => 'Tante', 'Cousin' => 'Cousin(e)',
                'Nephew' => 'Neveu', 'Niece' => 'Nièce', 'Grand Parent' => 'Grand-parent', 'Grand Child' => 'Petit-enfant', 'In-law' => 'Belle-famille',
                'Friend' => 'Ami(e)', 'Colleague' => 'Collègue', 'Employer' => 'Employeur', 'Employee' => 'Employé(e)', 'Landlord' => 'Propriétaire (bailleur)',
                'Tenant' => 'Locataire', 'Self' => 'Moi-même', 'Others' => 'Autre'],
            'origin_funds' => ['Salary' => 'Salaire', 'Savings' => 'Épargne', 'Business Income' => 'Revenus d\'activité', 'Pension' => 'Pension / retraite',
                'Gift' => 'Don reçu', 'Inheritance' => 'Héritage', 'Sale of Property' => 'Vente d\'un bien', 'Rental Income' => 'Revenus locatifs',
                'Investment' => 'Placements', 'Loan' => 'Prêt', 'Scholarship' => 'Bourse', 'Others' => 'Autre'],
            'reasons' => ['Family Maintainance' => 'Soutien familial', 'Education' => 'Scolarité', 'Medical Expenses' => 'Frais médicaux', 'Gift' => 'Cadeau',
                'Rent Property Expenses' => 'Loyer', 'Travel Expenses' => 'Voyage', 'Wedding' => 'Mariage', 'Funeral' => 'Funérailles',
                'Religious Festival' => 'Fête religieuse', 'Personal Savings' => 'Épargne personnelle', 'Construction' => 'Construction / travaux',
                'Purchase of Property' => 'Achat d\'un bien', 'Debt Settlement' => 'Remboursement d\'une dette', 'Utility Bills' => 'Factures (eau, électricité…)',
                'Charity' => 'Don à une association', 'Salary' => 'Salaire', 'Others' => 'Autre'],
        ],
        'b' => [
            'relations' => ['Supplier' => 'Fournisseur', 'Customer' => 'Client', 'Employee' => 'Employé', 'Partner' => 'Partenaire', 'Subsidiary' => 'Filiale',
                'Parent Company' => 'Maison mère', 'Branch' => 'Succursale', 'Contractor' => 'Prestataire', 'Shareholder' => 'Actionnaire',
                'Agent' => 'Agent / mandataire', 'Colleague' => 'Collègue', 'Others' => 'Autre'],
            'origin_funds' => ['Business Income' => 'Revenus d\'activité', 'Business Profit' => 'Bénéfices', 'Sales' => 'Ventes', 'Settlement' => 'Règlement',
                'Investment' => 'Investissement', 'Capital' => 'Capital social', 'Loan' => 'Prêt', 'Grant' => 'Subvention', 'Others' => 'Autre'],
            'reasons' => ['Advanced Goods Payments' => 'Paiement de marchandises', 'Import of Goods' => 'Importation de marchandises',
                'Other Business Services' => 'Prestations de services', 'Employee Compensation' => 'Salaires', 'Software Export' => 'Logiciels / IT',
                'Consulting' => 'Conseil', 'Commission' => 'Commissions', 'Rent' => 'Loyer commercial', 'Transport & Logistics' => 'Transport et logistique',
                'Conference or Training Fee' => 'Formation', 'Business Travel' => 'Voyage d\'affaires', 'Royalties' => 'Redevances',
                'Investment' => 'Investissement', 'Loan Repayment' => 'Remboursement de prêt', 'Tax Payment' => 'Paiement d\'impôts', 'Others' => 'Autre'],
        ],
    ];

    /** Libellés français des valeurs WacePay (anglais) reçues de l'API. */
    protected static function frLabel(string $value, string $label): string
    {
        $k = strtolower(trim($value));
        foreach (self::DEFAULT_LISTS as $grp) {
            foreach ($grp as $list) {
                foreach ($list as $code => $fr) {
                    if (strtolower($code) === $k) {
                        return $fr;
                    }
                }
            }
        }
        $more = ['family maintenance' => 'Soutien familial', 'medical' => 'Frais médicaux', 'school fees' => 'Frais de scolarité', 'business' => 'Affaires',
            'husband' => 'Mari', 'wife' => 'Épouse', 'other' => 'Autre', 'others' => 'Autre', 'self employed' => 'Indépendant', 'salary' => 'Salaire'];
        return $more[$k] ?? $label;
    }

    public function __construct(
        protected SwitchService $switch,
        protected FeeService $fees,
        protected FxService $fx,
        protected WacepayRemitClient $wace,
        protected TerrapayClient $terra,
        protected PawapayClient $pawa,
        protected RemitProfileService $profiles,
    ) {
    }

    // ------------------------------------------------------------ Options pour l'app

    public function options(User $user): array
    {
        $countries = [];
        foreach (RemitCountries::all() as $iso => $c) {
            $p = RemitCountries::partnerFor($iso);
            if (! $p || ! $this->partnerReady($p)) {
                continue;
            }
            $countries[] = $c + ['partner' => $p, 'partner_name' => $this->partnerName($p), 'methods' => RemitCountries::methodsOf($p),
                'beneficiary_kyc' => self::beneficiaryKycRequired($iso, $p),
                'postal_code_required' => in_array($iso, (array) config('flashpay.remit.postal_code_countries', []), true)];
        }
        $merchant = method_exists($user, 'merchant') ? $user->merchant : null;
        [$first, $last] = self::splitName((string) $user->full_name);

        return [
            'available' => (bool) $countries,
            'message' => $countries ? null : 'Les transferts internationaux ne sont pas encore ouverts. Réessayez bientôt.',
            'business_types' => collect(self::BUSINESS_TYPES)->map(fn ($t, $k) => ['key' => $k] + $t)->values(),
            'methods' => self::METHODS,
            'id_types' => self::ID_TYPES,
            'company_types' => self::COMPANY_TYPES,
            'countries' => $countries,
            'currency' => $this->walletOf($user)?->currency ?? config('flashpay.remit.from_currency', 'XAF'),
            'min_amount' => (int) config('flashpay.remit.min_amount', 1000),
            'max_amount' => (int) config('flashpay.remit.max_amount', 5000000),
            // Profil « international » (KYC expéditeur collecté une fois) : complet, manquant, pièce expirée
            'profile' => $this->profileSummary($user),
            // Entreprise (B2P / B2B) : pré-remplissage depuis le profil marchand
            'sender' => array_filter([
                'first_name' => $first, 'last_name' => $last, 'phone' => $user->phone, 'email' => $user->email,
                'id_number' => $user->id_number,
                'date_of_birth' => $user->date_of_birth instanceof \DateTimeInterface ? $user->date_of_birth->format('Y-m-d') : ($user->date_of_birth ? substr((string) $user->date_of_birth, 0, 10) : null),
                'country' => config('flashpay.remit.from_country', 'CG'), 'nationality' => config('flashpay.remit.from_country', 'CG'),
                'business_name' => $merchant?->business_name, 'city' => $merchant?->city, 'address' => $merchant?->address,
            ], fn ($v) => $v !== null && $v !== ''),
            'is_business' => (bool) $merchant,
            // Comptes à débiter : wallet, mobile money lié, carte liée
            'sources' => $this->sourcesOf($user),
        ];
    }

    /** Comptes que l'expéditeur peut débiter pour un transfert international. */
    public function sourcesOf(User $user): array
    {
        $wallet = $this->walletOf($user);
        $linked = collect();
        try {
            $linked = $user->linkedAccounts()->orderByDesc('is_default')->get();
        } catch (\Throwable) {
        }
        $mobiles = $linked->where('type', 'mobile_money')->map(fn ($a) => [
            'id' => $a->id, 'phone' => '+' . ltrim((string) $a->phone, '+'), 'country' => $a->country, 'operator' => $a->operator, 'label' => $a->label, 'is_default' => (bool) $a->is_default,
        ])->values()->all();
        if ($user->phone && ! collect($mobiles)->contains(fn ($m) => preg_replace('/\D/', '', $m['phone']) === preg_replace('/\D/', '', (string) $user->phone))) {
            $mobiles[] = ['id' => null, 'phone' => '+' . ltrim((string) $user->phone, '+'), 'country' => $wallet?->country, 'operator' => null, 'label' => 'Mon numéro', 'is_default' => ! $mobiles];
        }
        $cards = $linked->where('type', 'card')->map(fn ($a) => [
            'id' => $a->id, 'last4' => $a->card_last4 ?? null, 'brand' => $a->card_brand ?? null, 'holder' => $a->account_holder, 'is_default' => (bool) $a->is_default,
        ])->values()->all();
        $cardOn = in_array((string) config('payment_methods.card_driver', 'none'), ['sandbox', 'wacepay'], true);

        return [
            'wallet' => ['available' => (bool) $wallet, 'balance' => (int) ($wallet?->balance ?? 0), 'currency' => $wallet?->currency ?? config('flashpay.remit.from_currency', 'XAF')],
            'mobile' => ['available' => (bool) config('flashpay.remit.allow_mobile_source', true), 'accounts' => $mobiles],
            'card' => ['available' => $cardOn && (bool) config('flashpay.remit.allow_card_source', true), 'accounts' => $cards,
                'reason' => $cardOn ? null : 'Paiement par carte bientôt disponible.'],
        ];
    }

    /**
     * Compte à débiter : wallet (défaut), mobile money (compte lié ou numéro du titulaire, collecte
     * PEEX / WacePay / PawaPay selon le pays) ou carte liée (page 3-D Secure).
     * @return array{type:string, rail:string, phone:?string, country:?string, currency:string, label:string, linked_id:?int, collect_partner:?string, problems:array}
     */
    public function sourceFor(User $user, array $in, string $currency): array
    {
        $type = in_array($in['type'] ?? 'wallet', ['wallet', 'mobile', 'card'], true) ? ($in['type'] ?? 'wallet') : 'wallet';
        $out = ['type' => $type, 'rail' => 'wallet', 'phone' => null, 'country' => null, 'currency' => $currency, 'label' => 'Wallet FlashPay', 'linked_id' => null, 'collect_partner' => null, 'problems' => []];
        $flow = app(\App\Services\Peex\PeexFlowService::class);
        if ($type === 'mobile') {
            if (! config('flashpay.remit.allow_mobile_source', true)) {
                $out['problems'][] = 'Débit mobile money non disponible pour les transferts internationaux.';
                return $out;
            }
            $phone = trim((string) ($in['phone'] ?? $user->phone ?? ''));
            try {
                $route = app(\App\Services\Peex\PeexCorridors::class)->resolve($phone);
            } catch (\Throwable $e) {
                $out['problems'][] = $e->getMessage();
                return $out;
            }
            $out['phone'] = $route['phone'];
            $out['country'] = $route['country'];
            $out['label'] = 'Mobile money ' . $route['phone'];
            if (empty($route['collect'])) {
                $out['problems'][] = "Débit mobile money non disponible depuis {$route['country_name']}.";
                return $out;
            }
            $parity = (array) config('flashpay.fixed_parity', ['XAF', 'XOF']);
            if ($route['currency'] !== $currency && ! (in_array($route['currency'], $parity, true) && in_array($currency, $parity, true))) {
                $out['problems'][] = "Ce numéro est en {$route['currency']} : débitez un compte en {$currency}.";
                return $out;
            }
            try {
                $out['rail'] = $flow->collectRailFor($route);
                $linked = $flow->linkedMobile($user, $route['phone'], ['source_country' => $route['country']]);
                $out['linked_id'] = $linked?->id;
            } catch (\Throwable $e) {
                $out['problems'][] = str_replace('Recharge refusée', 'Débit refusé', $e->getMessage());
                return $out;
            }
            $out['collect_partner'] = match ($out['rail']) { 'digitwace' => 'digitwace', 'pawapay_momo' => 'pawapay', default => 'peex' };
        } elseif ($type === 'card') {
            $out['rail'] = 'card';
            $out['label'] = 'Carte bancaire';
            if (! in_array((string) config('payment_methods.card_driver', 'none'), ['sandbox', 'wacepay'], true) || ! config('flashpay.remit.allow_card_source', true)) {
                $out['problems'][] = 'Le paiement par carte n\'est pas encore disponible.';
                return $out;
            }
            try {
                $card = $flow->linkedCard($user, array_filter(['linked_account_id' => $in['linked_account_id'] ?? null]));
                $out['linked_id'] = $card?->id;
                if ($card) {
                    $out['label'] = trim(($card->card_brand ?? 'Carte') . ' •••• ' . ($card->card_last4 ?? ''));
                }
            } catch (\Throwable $e) {
                $out['problems'][] = str_replace('Recharge par carte impossible', 'Paiement par carte impossible', $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Frais du partenaire de versement (Console › Tarifs partenaires), calculés dans la devise du
     * bénéficiaire puis ramenés dans celle de l'expéditeur. 0 si aucun tarif n'est saisi.
     */
    protected function payoutFee(?string $partner, string $iso, array $fx, string $from, string $to): int
    {
        $key = ['pawapay' => 'pawapay_payout', 'digitwace' => 'digitwace_payout'][$partner] ?? null;
        if (! $key || ! config('flashpay.fees.pass_partner_fees', true) || (int) ($fx['amount'] ?? 0) <= 0) {
            return 0;
        }
        try {
            $f = \App\Support\PartnerFees::quoteFee($key, (int) $fx['amount'], $iso, \App\Support\PartnerFees::rates());
        } catch (\Throwable) {
            return 0;
        }
        $rate = (float) ($fx['rate'] ?? 1) ?: 1;
        return $from === $to ? $f : (int) ceil($f / $rate);
    }

    /** Frais du partenaire de collecte (mobile money, carte), ajoutés aux frais payés par le client. */
    protected function collectFee(array $src, int $gross): int
    {
        if ($src['type'] === 'wallet' || ! config('flashpay.fees.pass_partner_fees', true)) {
            return 0;
        }
        $key = $src['type'] === 'card'
            ? (config('payment_methods.card_driver') === 'wacepay' ? 'digitwace_card' : 'card')
            : ($src['collect_partner'] ?? 'peex') . '_collect';
        try {
            $payerBorne = $key === 'digitwace_collect' && config('flashpay.digitwace.collect_fee_bearer', 'payer') === 'payer';
            return \App\Support\PartnerFees::quoteFee($key, $gross, $src['country'], \App\Support\PartnerFees::rates(), gross: ! $payerBorne);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Services (opérateurs / banque / cash) du pays, groupés par moyen de réception, et disponibilité
     * de chaque mode : un mode que le partenaire ne propose pas pour ce pays est grisé dans l'app.
     * @return array{partner:string, methods:array, available:array<string,bool>, unavailable:array<string,string>, message:?string}
     */
    public function services(string $iso): array
    {
        $c = $this->country($iso);
        $p = $c['partner'];
        $offered = RemitCountries::methodsOf($p);
        $methods = [];
        $message = null;
        if ($p === 'terrapay') {
            $methods = ['wallet' => [['code' => '', 'name' => 'Mobile money (opérateur détecté automatiquement)']], 'bank' => [['code' => '', 'name' => 'Virement bancaire']]];
        } elseif ($p === 'pawapay') {
            $providers = null;
            try {
                $providers = $this->pawa->activeCountries()[PawapayClient::iso3($iso)] ?? [];
            } catch (\Throwable) {
                // configuration pawaPay illisible : opérateur détecté depuis le numéro
            }
            if ($providers === []) {
                $message = "PawaPay ne verse pas encore vers {$c['name']}.";
            } else {
                $list = [['code' => '', 'name' => 'Mobile money (opérateur détecté automatiquement)']];
                foreach ((array) $providers as $pr) {
                    $list[] = ['code' => $pr, 'name' => ucwords(strtolower(str_replace('_', ' ', preg_replace('/_[A-Z]{3}$/', '', $pr))))];
                }
                $methods = ['wallet' => $list];
            }
        } else {
            try {
                $out = ['wallet' => [], 'bank' => [], 'cash' => []];
                foreach ($this->wace->payoutServices($iso, $c['currency']) as $sv) {
                    $out[$sv['method']][] = ['code' => $sv['code'], 'name' => $sv['name']];
                }
                $methods = array_filter($out);
                if (! $methods) {
                    $message = "WacePay ne propose aucun mode de réception vers {$c['name']} ({$c['currency']}).";
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('WacePay : services de versement illisibles', ['country' => $iso, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                $message = "Transferts vers {$c['name']} momentanément indisponibles (le partenaire ne répond pas). Réessayez plus tard.";
            }
        }
        $available = [];
        $unavailable = [];
        foreach (array_keys(self::METHODS) as $m) {
            $available[$m] = in_array($m, $offered, true) && ! empty($methods[$m]);
            if (! $available[$m]) {
                $unavailable[$m] = ! in_array($m, $offered, true)
                    ? self::METHODS[$m] . ' : non proposé par ' . $this->partnerName($p) . '.'
                    : ($message ?? self::METHODS[$m] . " : non disponible vers {$c['name']}.");
            }
        }
        return ['partner' => $p, 'methods' => $methods, 'available' => $available, 'unavailable' => $unavailable, 'message' => $message];
    }

    /** Le mode de réception est-il proposé pour ce pays ? (contrôle du devis ; null = indéterminé) */
    protected function methodProblem(array $c, string $method): ?string
    {
        if (! in_array($method, RemitCountries::methodsOf($c['partner']), true)) {
            return (self::METHODS[$method] ?? $method) . " : non disponible vers {$c['name']}.";
        }
        if ($c['partner'] !== 'wacepay_remit') {
            return null;
        }
        try {
            $s = $this->services($c['iso']);
        } catch (\Throwable) {
            return null;
        }
        return $s['available'][$method] ?? true ? null : ($s['unavailable'][$method] ?? (self::METHODS[$method] ?? $method) . " : non disponible vers {$c['name']}.");
    }

    /** Banques (WacePay : liste avec BankID ; TerraPay : saisie libre + SWIFT). */
    public function banks(string $iso, array $service): array
    {
        $c = $this->country($iso);
        if ($c['partner'] !== 'wacepay_remit') {
            return ['free_text' => true, 'banks' => []];
        }
        try {
            $payer = $this->wace->payerCode($iso, $c['currency'], $service);
            $banks = $this->wace->banks($iso, $payer);
        } catch (\Throwable) {
            return ['free_text' => true, 'banks' => [], 'warning' => 'Liste des banques indisponible : saisissez le nom de la banque.'];
        }
        return ['free_text' => ! $banks, 'banks' => $banks];
    }

    public function cashPoints(string $iso, array $service): array
    {
        $c = $this->country($iso);
        if ($c['partner'] !== 'wacepay_remit') {
            return [];
        }
        try {
            $payer = $this->wace->payerCode($iso, $c['currency'], $service);
            return $this->wace->cashPoints($iso, $c['currency'], $payer, config('flashpay.remit.from_currency', 'XAF'));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Relations, origines des fonds, motifs selon le type (p2p, p2b, b2p, b2b). */
    public function lists(string $businessType, ?string $iso = null): array
    {
        $bt = isset(self::BUSINESS_TYPES[$businessType]) ? $businessType : 'p2p';
        $fallback = self::DEFAULT_LISTS[$bt[0] === 'b' ? 'b' : 'p'];
        if ((! $iso || RemitCountries::partnerFor($iso) === 'wacepay_remit') && $this->wace->enabled()) {
            try {
                $l = $this->wace->lists($bt);
                $fr = fn (array $m) => collect($m)->mapWithKeys(fn ($label, $code) => [$code => self::frLabel((string) $code, (string) $label)])->all();
                return [
                    'relations' => $l['relations'] ? $fr($l['relations']) : $fallback['relations'],
                    'origin_funds' => $l['origin_funds'] ? $fr($l['origin_funds']) : $fallback['origin_funds'],
                    'reasons' => $l['reasons'] ? $fr($l['reasons']) : $fallback['reasons'],
                    'source' => 'wacepay',
                ];
            } catch (\Throwable) {
                // listes de secours
            }
        }
        return $fallback + ['source' => 'flashpay'];
    }

    // ------------------------------------------------------------ Devis

    /** @param array{amount:int, country:string, method:string, business_type:string} $in */
    public function quote(User $user, array $in): array
    {
        $problems = [];
        $amount = (int) $in['amount'];
        $c = $this->country($in['country']);
        $bt = $in['business_type'] ?? 'p2p';
        $method = $in['method'] ?? 'wallet';
        $wallet = $this->walletOf($user);
        $currency = $wallet?->currency ?? config('flashpay.remit.from_currency', 'XAF');

        if (! isset(self::BUSINESS_TYPES[$bt])) {
            $problems[] = 'Type de transfert inconnu.';
        }
        if ($mp = $this->methodProblem($c, $method)) {
            $problems[] = $mp;
        }
        if ($amount < (int) config('flashpay.remit.min_amount', 1000)) {
            $problems[] = 'Montant minimum : ' . number_format((int) config('flashpay.remit.min_amount', 1000), 0, ',', ' ') . " {$currency}.";
        }
        if ($amount > (int) config('flashpay.remit.max_amount', 5000000)) {
            $problems[] = 'Montant maximum par transfert : ' . number_format((int) config('flashpay.remit.max_amount', 5000000), 0, ',', ' ') . " {$currency}.";
        }
        $codes = [];
        if ((int) ($user->kyc_tier ?? 0) < (int) config('flashpay.remit.min_kyc_tier', 1)) {
            $problems[] = 'Complétez la vérification de votre identité (Profil › Plafonds) pour envoyer à l\'international.';
            $codes[] = 'kyc_tier';
        }
        $profile = $this->profiles->status($user, $c['iso']);
        if ($profile['expired']) {
            $problems[] = 'Votre pièce d\'identité est expirée : mettez-la à jour dans « Activer l\'international » avant d\'envoyer.';
            $codes[] = 'id_expired';
        } elseif (! $profile['complete']) {
            $problems[] = 'Activez l\'international : il manque ' . implode(', ', array_values($profile['missing'])) . '.';
            $codes[] = 'profile_incomplete';
        }

        // Normes (Console › Tarifs internationaux) : zone + seuil, frais HT de la tranche, taxes → frais TTC
        $pricing = app(RemitFeeService::class);
        $priced = $pricing->compute($c['iso'], $bt, $method, $c['partner'] ?? null, $amount);
        $source = $this->sourceFor($user, (array) ($in['source'] ?? []), $currency);
        array_push($problems, ...$source['problems']);
        if ($priced['threshold'] && $amount > $priced['threshold']) {
            $problems[] = 'Montant maximum par transfert vers ' . $priced['zone']->name . ' : ' . number_format($priced['threshold'], 0, ',', ' ') . " {$currency}.";
        }
        try {
            $fx = $pricing->convert($amount, $currency, $c['currency']);
        } catch (\RuntimeException $e) {
            $fx = ['amount' => 0, 'rate' => 0, 'margin_percent' => 0, 'from' => $currency, 'to' => $c['currency'], 'source' => 'indisponible'];
            $problems[] = "Taux de change {$currency} → {$c['currency']} non disponible pour le moment.";
        }
        // L'expéditeur paie les trois : frais de service FlashPay + versement (partenaire du pays
        // destinataire) + collecte (partenaire qui débite son compte), calculée sur le total.
        $payoutFee = $this->payoutFee($c['partner'] ?? null, $c['iso'], $fx, $currency, $c['currency']);
        $collectFee = $this->collectFee($source, $amount + $priced['fee'] + $payoutFee);
        $fee = $priced['fee'] + $payoutFee + $collectFee;
        if ($source['type'] === 'wallet' && $wallet && (int) $wallet->balance < $amount + $fee) {
            $problems[] = 'Solde insuffisant (' . number_format((int) $wallet->balance, 0, ',', ' ') . " {$currency}).";
        }
        if (! $problems && ($p = $this->partnerBalanceProblem($c['partner'], $amount, $currency))) {
            $problems[] = $p;
        }

        return [
            'operation' => 'international_transfer',
            'business_type' => $bt,
            'business_type_label' => self::BUSINESS_TYPES[$bt]['label'] ?? $bt,
            'country' => $c,
            'partner' => $c['partner'],
            'partner_name' => $this->partnerName($c['partner']),
            'method' => $method,
            'method_label' => self::METHODS[$method] ?? $method,
            'amount' => $amount,
            'currency' => $currency,
            'fee' => $fee,
            'fee_ht' => $priced['fee_ht'],
            'fee_remit' => $priced['fee'],
            'collect_fee' => $collectFee,
            'payout_fee' => $payoutFee,
            'source' => array_diff_key($source, ['problems' => 1]),
            'taxes' => $priced['taxes'],
            'taxes_total' => $priced['taxes_total'],
            'zone' => $priced['zone'] ? ['id' => $priced['zone']->id, 'name' => $priced['zone']->name, 'threshold' => $priced['threshold']] : null,
            'tariff_id' => $priced['tariff']?->id,
            'total' => $amount + $fee,
            'fx' => $fx,
            'receive_amount' => (int) $fx['amount'],
            'receive_currency' => $c['currency'],
            'estimated' => $c['partner'] === 'wacepay_remit', // montant final fixé par le partenaire (change WacePay)
            'available' => empty($problems),
            'problems' => array_values(array_unique($problems)),
            'problem_codes' => $codes,
            'async' => true,
        ];
    }

    // ------------------------------------------------------------ Envoi

    /**
     * @param array $in amount, country, method, business_type, sender{…}, beneficiary{…}, account{…}, why{…}, note?
     */
    public function send(User $user, array $in): Transaction
    {
        $q = $this->quote($user, $in);
        if (! $q['available']) {
            throw new PeexException(implode(' ', $q['problems']));
        }
        $bt = $q['business_type'];
        $types = self::BUSINESS_TYPES[$bt];
        $country = $q['country'];
        $method = $q['method'];

        // Expéditeur = profil « international » (jamais les champs d'identité envoyés par l'app) ;
        // entreprise (B2P / B2B) : raison sociale + RCCM saisis, gérant = titulaire du profil
        $sender = $this->profiles->sender($user);
        if ($types['sender'] === 'B') {
            $biz = $this->normalizeParty((array) ($in['sender'] ?? []), 'B', 'expéditeur');
            $sender = ['type' => 'B'] + array_intersect_key($biz, array_flip(['business_name', 'business_type', 'registration_number', 'registered_at', 'business_description'])) + $sender;
            $sender['type'] = 'B';
        }
        $ben = $this->normalizeParty((array) ($in['beneficiary'] ?? []), $types['beneficiary'], 'bénéficiaire');
        $ben['country'] = $country['iso'];
        if ($country['beneficiary_kyc'] ?? self::beneficiaryKycRequired($country['iso'], $q['partner'])) {
            foreach ($types['beneficiary'] === 'B'
                ? ['address' => 'l\'adresse de l\'entreprise bénéficiaire', 'city' => 'la ville du bénéficiaire']
                : ['date_of_birth' => 'la date de naissance du bénéficiaire', 'id_type' => 'le type de pièce du bénéficiaire', 'id_number' => 'le numéro de pièce du bénéficiaire', 'address' => 'l\'adresse du bénéficiaire', 'city' => 'la ville du bénéficiaire'] as $k => $label) {
                if (empty($ben[$k])) {
                    throw new PeexException("Pour {$country['name']}, indiquez {$label}.");
                }
            }
        }
        $account = $this->normalizeAccount((array) ($in['account'] ?? []), $method, $country, $ben);
        if (empty($ben['phone'])) {
            $ben['phone'] = $account['msisdn'] ?? null;
        }
        if (empty($ben['phone'])) {
            throw new PeexException('Indiquez le numéro de téléphone du bénéficiaire.');
        }

        $remit = [
            'partner' => $q['partner'],
            'business_type' => $bt,
            'method' => $method,
            'country' => $country['iso'],
            'currency' => $country['currency'],
            'service' => array_filter(['code' => (string) data_get($in, 'service.code', ''), 'name' => (string) data_get($in, 'service.name', '')]) ?: ['code' => '', 'name' => ''],
            'sender' => $sender,
            'beneficiary' => $ben,
            'account' => $account,
            'why' => array_filter((array) ($in['why'] ?? [])),
            'note' => isset($in['note']) ? mb_substr((string) $in['note'], 0, 140) : null,
        ];

        // pawaPay : opérateur choisi dans l'app (sinon détecté depuis le numéro)
        if ($q['partner'] === 'pawapay' && $method === 'wallet' && empty($remit['account']['provider']) && ! empty($remit['service']['code'])) {
            $remit['account']['provider'] = $remit['service']['code'];
        }

        // Vérifications chez le partenaire AVANT tout débit
        $this->preflight($remit, $user);

        $wallet = $this->walletOf($user);
        $converted = $q['currency'] !== $q['receive_currency'];
        $display = $ben['type'] === 'B' ? $ben['business_name'] : trim($ben['first_name'] . ' ' . $ben['last_name']);

        $src = $q['source'];
        $payload = [
            'type' => 'international_transfer',
            'scope' => 'international',
            'source_rail' => $src['rail'],
            'source_account' => $src['type'] === 'mobile' ? $src['phone'] : null,
            'source_wallet_id' => $wallet->id,
            'destination_rail' => $q['partner'],
            'destination_account' => $account['msisdn'] ?? $account['account'] ?? ('cash:' . ($account['city'] ?? $country['iso'])),
            'amount' => $q['amount'],
            'fee' => $q['fee'],
            'currency' => $q['currency'],
            'destination_amount' => $converted ? $q['receive_amount'] : null,
            'destination_currency' => $converted ? $q['receive_currency'] : null,
            'initiated_by' => $user->id,
            'meta' => array_filter([
                'channel' => 'international',
                'destination_country' => $country['iso'],
                'source_country' => $src['country'] ?? $sender['country'],
                'sender_name' => $sender['type'] === 'B' ? $sender['business_name'] : trim($sender['first_name'] . ' ' . $sender['last_name']),
                'beneficiary_name' => $display,
                'business_type' => strtoupper($bt),
                'payout_method' => $method,
                'fx' => $converted ? $q['fx'] : null,
                'pricing' => ['zone' => $q['zone']['name'] ?? null, 'fee_ht' => $q['fee_ht'], 'taxes' => $q['taxes'], 'fee_ttc' => $q['fee'], 'tariff_id' => $q['tariff_id']],
                'remit' => $remit,
                'purpose' => $remit['why']['reason'] ?? null,
                'note' => $remit['note'],
                'debit_source' => $src['type'],
                'linked_account_id' => $src['linked_id'],
                'sender_phone' => $src['type'] === 'mobile' ? $src['phone'] : null,
                'fee_breakdown' => ($q['collect_fee'] || ($q['payout_fee'] ?? 0)) ? ['flashpay' => $q['fee_remit'], 'collect' => $q['collect_fee'], 'payout' => (int) ($q['payout_fee'] ?? 0)] : null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        // Carte : la collecte est confirmée par la page de paiement sécurisée (3-D Secure)
        if ($src['type'] === 'card') {
            $tx = $this->switch->createPending($payload, 'awaiting_card');
            return app(\App\Services\Payments\CardPaymentService::class)->startCheckout($tx);
        }

        return $this->switch->process($payload);
    }

    /** Contrôles bloquants côté partenaire, sans rien débiter. */
    protected function preflight(array $remit, User $user): void
    {
        try {
            if ($remit['partner'] === 'wacepay_remit') {
                $code = $this->wace->senderCode($remit['sender'], $user->id);
                $this->wace->beneficiaryCode($remit['beneficiary'] + ['country' => $remit['country']], $code, $user->id);
                $this->wace->payerCode($remit['country'], $remit['currency'], $remit['service']);
                if ($remit['method'] === 'bank' && empty($remit['account']['bank_id'])) {
                    throw new PeexException('Choisissez la banque du bénéficiaire dans la liste.');
                }
                if ($remit['method'] === 'cash' && empty($remit['account']['city'])) {
                    throw new PeexException('Choisissez la ville de retrait.');
                }
            } elseif ($remit['partner'] === 'pawapay') {
                // Numéro valide et opérateur reconnu par pawaPay, dans le bon pays
                $pred = $this->pawa->predictProvider($remit['account']['msisdn']);
                if ($pred['country'] && PawapayClient::iso3($remit['country']) && $pred['country'] !== PawapayClient::iso3($remit['country'])) {
                    throw new PeexException('Ce numéro n\'appartient pas à un opérateur de ' . (RemitCountries::get($remit['country'])['name'] ?? $remit['country']) . '.');
                }
            } elseif ($remit['partner'] === 'terrapay' && config('flashpay.terrapay.verify_account', true)) {
                $acc = $this->terra->accountStatus(TerrapayConnector::credit($remit), TerrapayConnector::fullName($remit['beneficiary']), TerrapayConnector::fullName($remit['sender']));
                if (! $acc['available']) {
                    throw new PeexException('Le compte du bénéficiaire ne peut pas recevoir ce transfert (' . ($acc['sub_status'] ?: $acc['status']) . '). Vérifiez le numéro et le nom.');
                }
            }
        } catch (RemitException $e) {
            throw new PeexException($this->friendly($e));
        }
    }

    // ------------------------------------------------------------ Utilitaires

    /** Résumé du profil international pour l'app. */
    public function profileSummary(User $user): array
    {
        $st = $this->profiles->status($user);
        $d = $st['data'];
        return [
            'complete' => $st['complete'],
            'expired' => $st['expired'],
            'expires_soon' => $st['expires_soon'],
            'missing' => array_values($st['missing']),
            'name' => trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')),
            'id_type' => $d['id_type'] ?? null,
            'id_number_masked' => isset($d['id_number']) ? str_repeat('•', max(0, mb_strlen($d['id_number']) - 3)) . mb_substr($d['id_number'], -3) : null,
            'id_expiry' => $d['id_expiry'] ?? null,
            'city' => $d['city'] ?? null,
            'country' => $d['country'] ?? null,
            'email' => $d['email'] ?? null,
        ];
    }

    /** TerraPay exige l'identité complète du bénéficiaire pour certains pays (REMIT_BENEFICIARY_KYC_COUNTRIES). */
    public static function beneficiaryKycRequired(string $iso, ?string $partner): bool
    {
        return $partner === 'terrapay' && in_array(strtoupper($iso), (array) config('flashpay.remit.beneficiary_kyc_countries', []), true);
    }

    protected function normalizeParty(array $p, string $type, string $who): array
    {
        $p = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $p);
        $p['type'] = $type;
        if ($type === 'B') {
            foreach (['business_name' => 'la raison sociale', 'registration_number' => 'le numéro RCCM (registre du commerce)'] as $k => $label) {
                if (empty($p[$k])) {
                    throw new PeexException("Indiquez {$label} de l'entreprise ({$who}).");
                }
            }
            $p['business_type'] ??= 'SARL';
            // Représentant (gérant) : nom par défaut = raison sociale
            $p['first_name'] = $p['first_name'] ?? $p['business_name'];
            $p['last_name'] = $p['last_name'] ?? ($p['business_type'] ?? 'SARL');
        } else {
            if (empty($p['first_name']) || empty($p['last_name'])) {
                throw new PeexException("Indiquez le prénom et le nom de l'{$who}.");
            }
        }
        if ($who === 'expéditeur' && $type === 'B' && empty($p['registered_at'])) {
            // KYC entreprise : date d'immatriculation (l'identité du gérant vient du profil international)
            throw new PeexException('Indiquez : date d\'immatriculation de l\'entreprise (exigé pour les transferts internationaux).');
        }
        return array_filter($p, fn ($v) => $v !== null && $v !== '');
    }

    protected function normalizeAccount(array $a, string $method, array $country, array $ben): array
    {
        $a = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $a);
        if ($method === 'wallet') {
            $digits = preg_replace('/\D/', '', (string) ($a['msisdn'] ?? ''));
            if (strlen($digits) < 6) {
                throw new PeexException('Numéro mobile money du bénéficiaire invalide.');
            }
            if (! str_starts_with($digits, $country['dial']) || strlen($digits) <= 10) {
                // Le 0 initial fait partie du numéro international au Congo, Gabon, Côte d'Ivoire, Bénin
                $local = \App\Services\Peex\PeexCorridors::keepsLeadingZero($country['iso']) ? $digits : ltrim($digits, '0');
                $digits = str_starts_with($digits, $country['dial']) && strlen($digits) > 10 ? $digits : $country['dial'] . $local;
            }
            return array_filter(['msisdn' => '+' . $digits, 'provider' => $a['provider'] ?? null]);
        }
        if ($method === 'bank') {
            $acc = strtoupper(preg_replace('/\s+/', '', (string) ($a['account'] ?? '')));
            if (strlen($acc) < 5) {
                throw new PeexException('Numéro de compte / IBAN du bénéficiaire invalide.');
            }
            if (empty($a['bank_name']) && empty($a['bank_id'])) {
                throw new PeexException('Indiquez la banque du bénéficiaire.');
            }
            return array_filter(['account' => $acc, 'bank_id' => $a['bank_id'] ?? null, 'bank_name' => $a['bank_name'] ?? null,
                'swift' => isset($a['swift']) ? strtoupper((string) $a['swift']) : null, 'branch' => $a['branch'] ?? null, 'account_type' => $a['account_type'] ?? null]);
        }
        // Retrait cash : ville + question / réponse secrètes communiquées au bénéficiaire
        foreach (['city' => 'la ville de retrait', 'question' => 'la question secrète', 'answer' => 'la réponse secrète'] as $k => $label) {
            if (empty($a[$k])) {
                throw new PeexException("Indiquez {$label}.");
            }
        }
        return array_filter(['city' => $a['city'], 'collection_code' => $a['collection_code'] ?? '', 'question' => $a['question'], 'answer' => $a['answer']]);
    }

    protected function country(string $iso): array
    {
        $c = RemitCountries::get($iso);
        $p = RemitCountries::partnerFor($iso);
        if (! $c || ! $p) {
            throw new PeexException('Transfert international non disponible vers ce pays.');
        }
        if (! $this->partnerReady($p)) {
            throw new PeexException('Transferts vers ' . $c['name'] . ' momentanément indisponibles.');
        }
        return $c + ['partner' => $p];
    }

    public function partnerReady(string $p): bool
    {
        return match ($p) {
            'wacepay_remit' => config('flashpay.rails.wacepay_remit.enabled') && $this->wace->enabled(),
            'terrapay' => config('flashpay.rails.terrapay.enabled') && $this->terra->enabled(),
            'pawapay' => config('flashpay.rails.pawapay.enabled') && $this->pawa->enabled(),
            default => false,
        };
    }

    public function partnerName(?string $p): string
    {
        return RemitCountries::partnerName($p);
    }

    protected function partnerBalanceProblem(string $partner, int $amount, string $currency): ?string
    {
        if ($partner !== 'wacepay_remit' || ! config('flashpay.wacepay_remit.check_balance', true)) {
            return null;
        }
        try {
            $b = Cache::remember('wacepay_remit:balance', 60, fn () => $this->wace->balance());
        } catch (\Throwable) {
            return null; // solde inconnu : ne bloque pas
        }
        if (strtoupper($b['currency']) === strtoupper($currency) && $b['balance'] < $amount) {
            return 'Transferts internationaux momentanément suspendus (réapprovisionnement en cours). Réessayez plus tard ou envoyez un montant plus petit.';
        }
        return null;
    }

    protected function friendly(RemitException $e): string
    {
        $map = [
            '1007' => 'La ville choisie ne correspond pas au point de retrait.', '1011' => 'Bénéficiaire non reconnu par le partenaire.',
            '1017' => 'Banque inconnue ou indisponible.', '2001' => 'Devise non disponible pour ce pays.', '2003' => 'Pays non disponible.',
            '2008' => 'Service de réception indisponible pour ce pays.', '2010' => 'Taux de change non configuré chez le partenaire.',
            '2011' => 'Frais non configurés chez le partenaire pour ce montant.', '2015' => 'Service de réception indisponible.',
            '3001' => 'Pour une entreprise, le type de pièce doit être le RCCM.', '3003' => 'Type de pièce d\'identité non accepté.',
            '6001' => 'Pays du bénéficiaire non disponible.', '6002' => 'Ville du bénéficiaire inconnue.', '6008' => 'Le nom du bénéficiaire ne correspond pas au titulaire du compte.',
        ];
        return $map[$e->partnerCode ?? ''] ?? preg_replace('/^(WacePay|TerraPay)\s*(\(\w+\))?\s*:\s*/', '', $e->getMessage());
    }

    protected function walletOf(User $user): ?Wallet
    {
        return $user->wallet ?? Wallet::where('user_id', $user->id)->first();
    }

    public static function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        if (count($parts) < 2) {
            return [$full, ''];
        }
        $first = array_shift($parts);
        return [$first, implode(' ', $parts)];
    }
}
