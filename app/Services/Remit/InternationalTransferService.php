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

    /** Listes de secours (si le partenaire ne les fournit pas). */
    protected const DEFAULT_LISTS = [
        'p' => [
            'relations' => ['Family' => 'Famille', 'Brother' => 'Frère', 'Sister' => 'Sœur', 'Mother' => 'Mère', 'Parent' => 'Parent', 'Spouse' => 'Conjoint(e)', 'Friend' => 'Ami(e)', 'Self' => 'Moi-même', 'Colleague' => 'Collègue'],
            'origin_funds' => ['Salary' => 'Salaire', 'Savings' => 'Épargne', 'Business Income' => 'Revenus d\'activité', 'Loan' => 'Prêt', 'Others' => 'Autre'],
            'reasons' => ['Family Maintainance' => 'Soutien familial', 'Education' => 'Scolarité', 'Medical Expenses' => 'Frais médicaux', 'Gift' => 'Cadeau', 'Rent Property Expenses' => 'Loyer', 'Travel Expenses' => 'Voyage'],
        ],
        'b' => [
            'relations' => ['Supplier' => 'Fournisseur', 'Customer' => 'Client', 'Employee' => 'Employé', 'Partner' => 'Partenaire', 'Subsidiary' => 'Filiale', 'Colleague' => 'Collègue'],
            'origin_funds' => ['Business Income' => 'Revenus d\'activité', 'Business Profit' => 'Bénéfices', 'Settlement' => 'Règlement', 'Loan' => 'Prêt', 'Others' => 'Autre'],
            'reasons' => ['Advanced Goods Payments' => 'Paiement de marchandises', 'Other Business Services' => 'Prestations de services', 'Employee Compensation' => 'Salaires', 'Software Export' => 'Logiciels / IT', 'Conference or Training Fee' => 'Formation', 'Business Travel' => 'Voyage d\'affaires'],
        ],
    ];

    public function __construct(
        protected SwitchService $switch,
        protected FeeService $fees,
        protected FxService $fx,
        protected WacepayRemitClient $wace,
        protected TerrapayClient $terra,
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
            $countries[] = $c + ['partner' => $p, 'partner_name' => $this->partnerName($p), 'methods' => RemitCountries::methodsOf($p)];
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
            // Pré-remplissage de l'expéditeur (modifiable dans l'app)
            'sender' => array_filter([
                'first_name' => $first, 'last_name' => $last, 'phone' => $user->phone, 'email' => $user->email,
                'id_number' => $user->id_number,
                'date_of_birth' => $user->date_of_birth instanceof \DateTimeInterface ? $user->date_of_birth->format('Y-m-d') : ($user->date_of_birth ? substr((string) $user->date_of_birth, 0, 10) : null),
                'country' => config('flashpay.remit.from_country', 'CG'), 'nationality' => config('flashpay.remit.from_country', 'CG'),
                'business_name' => $merchant?->business_name, 'city' => $merchant?->city, 'address' => $merchant?->address,
            ], fn ($v) => $v !== null && $v !== ''),
            'is_business' => (bool) $merchant,
        ];
    }

    /** Services (opérateurs / banque / cash) du pays, groupés par moyen de réception. */
    public function services(string $iso): array
    {
        $c = $this->country($iso);
        $p = $c['partner'];
        if ($p === 'terrapay') {
            return ['partner' => $p, 'methods' => ['wallet' => [['code' => '', 'name' => 'Mobile money (opérateur détecté automatiquement)']], 'bank' => [['code' => '', 'name' => 'Virement bancaire']]]];
        }
        $out = ['wallet' => [], 'bank' => [], 'cash' => []];
        foreach ($this->wace->payoutServices($iso, $c['currency']) as $s) {
            $out[$s['method']][] = ['code' => $s['code'], 'name' => $s['name']];
        }
        return ['partner' => $p, 'methods' => array_filter($out)];
    }

    /** Banques (WacePay : liste avec BankID ; TerraPay : saisie libre + SWIFT). */
    public function banks(string $iso, array $service): array
    {
        $c = $this->country($iso);
        if ($c['partner'] !== 'wacepay_remit') {
            return ['free_text' => true, 'banks' => []];
        }
        $payer = $this->wace->payerCode($iso, $c['currency'], $service);
        return ['free_text' => false, 'banks' => $this->wace->banks($iso, $payer)];
    }

    public function cashPoints(string $iso, array $service): array
    {
        $c = $this->country($iso);
        if ($c['partner'] !== 'wacepay_remit') {
            return [];
        }
        $payer = $this->wace->payerCode($iso, $c['currency'], $service);
        return $this->wace->cashPoints($iso, $c['currency'], $payer, config('flashpay.remit.from_currency', 'XAF'));
    }

    /** Relations, origines des fonds, motifs selon le type (p2p, p2b, b2p, b2b). */
    public function lists(string $businessType, ?string $iso = null): array
    {
        $bt = isset(self::BUSINESS_TYPES[$businessType]) ? $businessType : 'p2p';
        $fallback = self::DEFAULT_LISTS[$bt[0] === 'b' ? 'b' : 'p'];
        if ((! $iso || RemitCountries::partnerFor($iso) === 'wacepay_remit') && $this->wace->enabled()) {
            try {
                $l = $this->wace->lists($bt);
                return [
                    'relations' => $l['relations'] ?: $fallback['relations'],
                    'origin_funds' => $l['origin_funds'] ?: $fallback['origin_funds'],
                    'reasons' => $l['reasons'] ?: $fallback['reasons'],
                ];
            } catch (\Throwable) {
                // listes de secours
            }
        }
        return $fallback;
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
        if (! in_array($method, RemitCountries::methodsOf($c['partner']), true)) {
            $problems[] = (self::METHODS[$method] ?? $method) . " : non disponible vers {$c['name']}.";
        }
        if ($amount < (int) config('flashpay.remit.min_amount', 1000)) {
            $problems[] = 'Montant minimum : ' . number_format((int) config('flashpay.remit.min_amount', 1000), 0, ',', ' ') . " {$currency}.";
        }
        if ($amount > (int) config('flashpay.remit.max_amount', 5000000)) {
            $problems[] = 'Montant maximum par transfert : ' . number_format((int) config('flashpay.remit.max_amount', 5000000), 0, ',', ' ') . " {$currency}.";
        }
        if ((int) ($user->kyc_tier ?? 0) < (int) config('flashpay.remit.min_kyc_tier', 1)) {
            $problems[] = 'Complétez la vérification de votre identité (Profil › Plafonds) pour envoyer à l\'international.';
        }

        $fee = $this->fees->fee('international_transfer', 'international', $amount) ?: $this->fees->fee('p2p', 'international', $amount);
        try {
            $fx = $this->fx->convert($amount, $currency, $c['currency']);
        } catch (\RuntimeException $e) {
            $fx = ['amount' => 0, 'rate' => 0, 'margin_percent' => 0, 'from' => $currency, 'to' => $c['currency'], 'source' => 'indisponible'];
            $problems[] = "Taux de change {$currency} → {$c['currency']} non disponible pour le moment.";
        }
        if ($wallet && (int) $wallet->balance < $amount + $fee) {
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
            'total' => $amount + $fee,
            'fx' => $fx,
            'receive_amount' => (int) $fx['amount'],
            'receive_currency' => $c['currency'],
            'estimated' => $c['partner'] === 'wacepay_remit', // montant final fixé par le partenaire (change WacePay)
            'available' => empty($problems),
            'problems' => array_values(array_unique($problems)),
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

        $sender = $this->normalizeParty((array) ($in['sender'] ?? []), $types['sender'], 'expéditeur');
        $sender += ['phone' => $user->phone, 'email' => $user->email];
        $sender['country'] ??= config('flashpay.remit.from_country', 'CG');
        $ben = $this->normalizeParty((array) ($in['beneficiary'] ?? []), $types['beneficiary'], 'bénéficiaire');
        $ben['country'] = $country['iso'];
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

        // Vérifications chez le partenaire AVANT tout débit
        $this->preflight($remit, $user);

        $wallet = $this->walletOf($user);
        $converted = $q['currency'] !== $q['receive_currency'];
        $display = $ben['type'] === 'B' ? $ben['business_name'] : trim($ben['first_name'] . ' ' . $ben['last_name']);

        return $this->switch->process([
            'type' => 'international_transfer',
            'scope' => 'international',
            'source_rail' => 'wallet',
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
                'source_country' => $sender['country'],
                'sender_name' => $sender['type'] === 'B' ? $sender['business_name'] : trim($sender['first_name'] . ' ' . $sender['last_name']),
                'beneficiary_name' => $display,
                'business_type' => strtoupper($bt),
                'payout_method' => $method,
                'fx' => $converted ? $q['fx'] : null,
                'remit' => $remit,
                'purpose' => $remit['why']['reason'] ?? null,
                'note' => $remit['note'],
            ], fn ($v) => $v !== null && $v !== ''),
        ]);
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
        if ($who === 'expéditeur') {
            // KYC exigé par les partenaires : pièce du particulier, ou du gérant + date d'immatriculation pour une entreprise
            $required = $type === 'B'
                ? ['registered_at' => 'date d\'immatriculation de l\'entreprise', 'date_of_birth' => 'date de naissance du gérant', 'id_expiry' => 'date d\'expiration de la pièce du gérant']
                : ['id_number' => 'numéro de pièce d\'identité', 'date_of_birth' => 'date de naissance', 'id_expiry' => 'date d\'expiration de la pièce'];
            foreach ($required as $k => $label) {
                if (empty($p[$k])) {
                    throw new PeexException("Indiquez : {$label} (exigé pour les transferts internationaux).");
                }
            }
            if (! empty($p['id_expiry']) && strtotime((string) $p['id_expiry']) < strtotime('today')) {
                throw new PeexException('Votre pièce d\'identité est expirée : mettez-la à jour pour envoyer à l\'international.');
            }
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
            default => false,
        };
    }

    public function partnerName(?string $p): string
    {
        return ['wacepay_remit' => 'WacePay', 'terrapay' => 'TerraPay'][$p] ?? (string) $p;
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
