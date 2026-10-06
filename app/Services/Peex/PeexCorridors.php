<?php

namespace App\Services\Peex;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Résolution pays / opérateur / format international d'un numéro,
 * à partir de config('flashpay.corridors'), et zones (national / régional / international).
 */
class PeexCorridors
{
    /** Pays dont le 0 initial fait partie du numéro international. */
    protected const LEADING_ZERO = ['CG', 'GA', 'CI', 'BJ'];

    public function __construct(protected ?PeexClient $client = null)
    {
    }

    /** Partenaires de paiement gérant les flux (collecte / versement). */
    public const PARTNERS = [
        'peex' => ['name' => 'PEEX', 'flows' => ['collect', 'payout']],
        'digitwace' => ['name' => 'WacePay (Digitwace)', 'flows' => ['payout']],
    ];

    public static function partnerName(?string $key): ?string
    {
        return $key ? (self::PARTNERS[$key]['name'] ?? ucfirst($key)) : null;
    }

    public function all(): array
    {
        $all = config('flashpay.corridors', []) + $this->extraCountries();
        // Partenaire par défaut : PEEX, sauf versements listés dans DIGITWACE_PAYOUT_COUNTRIES
        $wace = (array) config('flashpay.digitwace.payout_countries', []);
        $home = strtoupper((string) config('flashpay.peex.default_country', 'CG'));
        foreach ($all as $iso => &$c) {
            $c['collect_partner'] ??= 'peex';
            $c['payout_partner'] ??= (in_array($iso, $wace, true) || (in_array('*', $wace, true) && $iso !== $home)) ? 'digitwace' : 'peex';
        }
        unset($c);
        foreach ($this->overrides() as $iso => $o) {
            if (! isset($all[$iso])) {
                continue;
            }
            foreach (['collect', 'payout', 'payout_api', 'collect_partner', 'payout_partner'] as $k) {
                if (($o[$k] ?? null) !== null) {
                    $all[$iso][$k] = $o[$k];
                }
            }
        }
        return $all;
    }

    /** Pays ajoutés par la synchro WacePay / la console (table corridor_countries). */
    public function extraCountries(): array
    {
        try {
            return Cache::remember('flashpay:corridor_countries', 300, fn () => \App\Models\CorridorCountry::all()->mapWithKeys(fn ($c) => [$c->country => [
                'name' => $c->name, 'zone' => $c->zone, 'dial' => $c->dial, 'local_length' => (int) $c->local_length,
                'currency' => $c->currency, 'flag' => $c->flag ?? '', 'collect' => false, 'payout' => false,
                'payout_api' => 'remittance', 'operators' => $c->operators ?: [],
                'collect_partner' => 'digitwace', 'payout_partner' => 'digitwace', 'source' => $c->source,
            ]])->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** Valeurs de config/corridors.php (+ .env), sans les surcharges de la console. */
    public function defaults(): array
    {
        return config('flashpay.corridors', []) + $this->extraCountries();
    }

    /** Surcharges enregistrées depuis la console (table corridor_settings). */
    public function overrides(): array
    {
        try {
            return Cache::remember('flashpay:corridor_settings', 300, fn () => \App\Models\CorridorSetting::all()
                ->mapWithKeys(fn ($o) => [$o->country => ['collect' => $o->collect, 'payout' => $o->payout, 'payout_api' => $o->payout_api,
                    'collect_partner' => $o->collect_partner, 'payout_partner' => $o->payout_partner]])
                ->all());
        } catch (\Throwable) {
            return []; // table absente (migration non lancée)
        }
    }

    public static function flushOverrides(): void
    {
        Cache::forget('flashpay:corridor_settings');
        Cache::forget('flashpay:corridor_countries');
    }

    public function country(string $iso): array
    {
        $iso = strtoupper($iso);
        $c = $this->all()[$iso] ?? null;
        if (! $c) {
            throw new PeexException("Pays non couvert par FlashPay : {$iso}");
        }
        return $c + ['iso' => $iso];
    }

    /**
     * national      : même pays
     * regional      : même zone monétaire (CEMAC ↔ CEMAC, UEMOA ↔ UEMOA)
     * international : zones différentes (CEMAC ↔ UEMOA, RDC, Guinée…)
     */
    public function scope(string $fromIso, string $toIso): string
    {
        if (strtoupper($fromIso) === strtoupper($toIso)) {
            return 'national';
        }
        return $this->country($fromIso)['zone'] === $this->country($toIso)['zone'] ? 'regional' : 'international';
    }

    /**
     * @return array{country:string,phone:string,local:string,corridor:?string,operator:?string,rail:string,
     *               currency:string,zone:string,country_name:string,flag:string,collect:bool,payout:bool}
     */
    public function resolve(string $phone, ?string $countryHint = null, bool $askPeex = false): array
    {
        $raw = trim($phone);
        $digits = preg_replace('/\D+/', '', $raw);
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            throw new PeexException('Numéro de téléphone manquant');
        }

        $iso = null;
        $local = $digits;

        // 1. Indicatif présent ?
        foreach ($this->all() as $code => $c) {
            if (str_starts_with($digits, $c['dial']) && ($international || strlen($digits) > $c['local_length'])) {
                $iso = $code;
                $local = substr($digits, strlen($c['dial']));
                break;
            }
        }

        if (! $iso && $international) {
            throw new PeexException("Indicatif international non couvert par FlashPay : {$phone}");
        }

        // 2. Sinon pays indiqué / pays par défaut
        $iso ??= strtoupper($countryHint ?: config('flashpay.peex.default_country', 'CG'));
        $c = $this->country($iso);
        $len = $c['local_length'];

        // Normalisation du format national
        if (in_array($iso, self::LEADING_ZERO, true)) {
            if ($iso === 'BJ' && strlen($local) === 8) {
                $local = '01' . $local; // anciens numéros béninois à 8 chiffres
            } elseif (strlen($local) === $len - 1 && ! str_starts_with($local, '0')) {
                // 55521222 -> 055521222 ; mais 05521222 (un chiffre manquant) reste invalide
                $local = '0' . $local;
            }
        } elseif (strlen($local) === $len + 1 && str_starts_with($local, '0')) {
            $local = substr($local, 1); // préfixe national (ex. RDC 081… -> 81…)
        }

        if (strlen($local) !== $len) {
            throw new PeexException("Numéro invalide pour {$c['name']} : {$phone} (attendu {$len} chiffres après +{$c['dial']})");
        }

        [$corridor, $operator, $rail] = $this->operatorFor($c, $local);

        $result = [
            'country' => $iso,
            'country_name' => $c['name'],
            'flag' => $c['flag'] ?? '',
            'zone' => $c['zone'],
            'phone' => '+' . $c['dial'] . $local,
            'local' => $local,
            'corridor' => $corridor,
            'operator' => $operator,
            'rail' => $rail,
            'currency' => $c['currency'],
            'collect' => (bool) $c['collect'],
            'payout' => (bool) $c['payout'],
        ];

        // 3. Opérateur inconnu : on demande à PEEX (mis en cache 30 jours)
        if (! $corridor && $askPeex && $this->client) {
            $result = $this->enrichFromPeex($result);
        }

        return $result;
    }

    protected function operatorFor(array $c, string $local): array
    {
        $best = [null, null, 'peex'];
        $bestLen = 0;
        foreach ($c['operators'] as $key => $op) {
            foreach ($op['prefixes'] as $prefix) {
                if (str_starts_with($local, $prefix) && strlen($prefix) > $bestLen) {
                    $bestLen = strlen($prefix);
                    $best = [$key, $op['label'], $op['rail']];
                }
            }
        }
        return $best;
    }

    protected function enrichFromPeex(array $r): array
    {
        try {
            $info = Cache::remember('peex:mnc:' . $r['phone'], now()->addDays(30), fn () => $this->client->verifyPhone($r['phone']));
            $mnc = $info['mnc'] ?? null;
            if ($mnc) {
                $op = $this->country($r['country'])['operators'][$mnc] ?? null;
                $r['corridor'] = $mnc;
                $r['operator'] = $op['label'] ?? strtoupper(explode('-', $mnc)[0]);
                $r['rail'] = $op['rail'] ?? 'peex';
            }
        } catch (\Throwable $e) {
            Log::info('PEEX verify_phoneNumber indisponible', ['phone' => $r['phone'], 'error' => $e->getMessage()]);
        }
        return $r;
    }

    /** Liste publique (app mobile / admin). */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->all() as $iso => $c) {
            $out[] = [
                'country' => $iso,
                'name' => $c['name'],
                'flag' => $c['flag'] ?? '',
                'zone' => $c['zone'],
                'dial' => '+' . $c['dial'],
                'local_length' => $c['local_length'],
                'currency' => $c['currency'],
                'collect' => (bool) $c['collect'],
                'payout' => (bool) $c['payout'],
                'payout_api' => $c['payout_api'],
                'source' => $c['source'] ?? 'config',
                'collect_partner' => $c['collect_partner'] ?? 'peex',
                'payout_partner' => $c['payout_partner'] ?? 'peex',
                'operators' => collect($c['operators'])->map(fn ($op, $key) => [
                    'corridor' => $key, 'label' => $op['label'], 'rail' => $op['rail'], 'prefixes' => $op['prefixes'],
                ])->values()->all(),
            ];
        }
        return $out;
    }
}
