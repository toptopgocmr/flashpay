<?php

namespace App\Services\Digitwace;

use App\Models\CorridorCountry;
use App\Models\CorridorSetting;
use App\Models\WacepayCoverage;
use App\Services\Peex\PeexCorridors;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Couverture WacePay : récupère la liste des payeurs (getPayerCode) et
 *  1. enregistre chaque payeur avec ses services (collecte / versement) ;
 *  2. crée les pays absents du catalogue FlashPay (corridor_countries) et
 *     les ouvre avec WacePay comme partenaire ;
 *  3. pour les pays déjà couverts par PEEX, ne change rien : la console
 *     indique « WacePay disponible » et l'admin choisit le partenaire.
 */
class CoverageService
{
    public function __construct(protected DigitwaceClient $client, protected PeexCorridors $corridors)
    {
    }

    /** @return array{payers:int, countries:int, added:string[], skipped:string[], unknown:string[]} */
    public function sync(): array
    {
        $items = $this->client->payerCodes(null);
        // Réponse groupée par pays ({ "SN": [ … ] }) ou liste plate
        $flat = [];
        foreach ($items as $k => $v) {
            if (is_array($v) && array_is_list($v) && ! isset($v['payerCode'])) {
                foreach ($v as $p) {
                    $flat[] = is_array($p) ? $p + ['__country' => $k] : $p;
                }
            } else {
                $flat[] = $v;
            }
        }

        $now = now();
        $seen = [];
        $unknown = [];
        foreach ($flat as $p) {
            if (! is_array($p)) {
                continue;
            }
            $row = $this->normalize($p);
            if (! $row['country'] || ! $row['payer_code']) {
                $unknown[] = self::pick($p, ['name', 'country', 'countryCode', '__country']) ?? mb_substr(json_encode($p), 0, 80);
                continue;
            }
            WacepayCoverage::updateOrCreate(
                ['country' => $row['country'], 'payer_code' => $row['payer_code']],
                $row + ['raw' => $p, 'synced_at' => $now],
            );
            $seen[$row['country']] = true;
        }
        // Payeurs disparus de la liste WacePay : désactivés
        WacepayCoverage::where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', $now))
            ->update(['payin' => false, 'payout' => false]);

        [$added, $skipped] = $this->ensureCorridors(array_keys($seen));
        PeexCorridors::flushOverrides();
        cache()->put('wacepay:coverage:synced_at', $now->toIso8601String(), now()->addYear());

        Log::info('Couverture WacePay synchronisée', ['payers' => count($flat), 'countries' => count($seen), 'added' => $added]);

        return ['payers' => count($flat), 'countries' => count($seen), 'added' => $added, 'skipped' => $skipped, 'unknown' => array_values(array_unique($unknown))];
    }

    /** Première valeur texte trouvée (les objets {code, name…} sont lus à l'intérieur). */
    public static function pick(array $p, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = data_get($p, $k);
            if (is_array($v)) {
                $v = collect(['code', 'iso2', 'isoCode', 'countryCode', 'alpha2', 'name', 'label', 'value'])
                    ->map(fn ($kk) => $v[$kk] ?? null)->first(fn ($x) => is_scalar($x) && $x !== '');
            }
            if (is_scalar($v) && (string) $v !== '' && ! is_bool($v)) {
                return (string) $v;
            }
        }
        return null;
    }

    /** Pays ISO2 d'un service / payeur WacePay (code, objet pays, ou « MTN (CONGO) » / « Congo (CG) »). */
    public static function countryOf(array $p): ?string
    {
        foreach (['countryCode', 'country_code', 'iso2', 'countryIso', 'iso', '__country', 'country', 'countryName', 'country_name'] as $k) {
            $v = self::pick($p, [$k]);
            if ($v && ($iso = CountryReference::iso2(trim(preg_replace('/\s*\(.*\)\s*/', '', $v))) ?? (preg_match('/\(([A-Z]{2})\)/i', $v, $m) ? strtoupper($m[1]) : null))) {
                return $iso;
            }
        }
        foreach (['name', 'serviceName', 'label', 'description'] as $k) {
            $v = self::pick($p, [$k]);
            if ($v && preg_match('/\(([^)]+)\)/', $v, $m)) {
                $inner = trim($m[1]);
                $iso = strlen($inner) === 2 ? strtoupper($inner) : CountryReference::iso2($inner);
                if ($iso) {
                    return $iso;
                }
            }
        }
        return null;
    }

    /** Champs WacePay (noms variables) → ligne de couverture. */
    public function normalize(array $p): array
    {
        $get = fn (array $keys) => self::pick($p, $keys);
        $country = self::countryOf($p);
        $type = strtolower((string) $get(['type', 'payerType', 'payer_type', 'method', 'paymentMethod', 'category']));
        $method = match (true) {
            str_contains($type, 'bank') || str_contains($type, 'banque') => 'bank',
            str_contains($type, 'cash') => 'cash',
            default => 'wallet',
        };

        // Services : drapeaux explicites ou liste de types de transactions
        $services = strtoupper((string) json_encode(collect(['services', 'operations', 'transactionTypes', 'transaction_types', 'products', 'flows'])->map(fn ($k) => data_get($p, $k))->first(fn ($v) => $v !== null && $v !== '') ?? ''));
        $flag = function (array $keys) use ($p) {
            foreach ($keys as $k) {
                $v = data_get($p, $k);
                if (is_bool($v) || is_scalar($v)) {
                    return filter_var($v, FILTER_VALIDATE_BOOLEAN);
                }
            }
            return null;
        };
        $payin = $flag(['payin', 'payIn', 'isPayin', 'canPayin', 'collection', 'collect'])
            ?? (str_contains($services, 'PAYIN') || str_contains($services, 'COLLECT') ? true : null);
        $payout = $flag(['payout', 'payOut', 'isPayout', 'canPayout', 'disbursement', 'remittance'])
            ?? (str_contains($services, 'PAYOUT') || str_contains($services, 'DISBURSE') || str_contains($services, 'REMITTANCE') ? true : null);
        if ($payin === null && $payout === null) {
            $payout = true; // getPayerCode = payeurs de versement (doc de charge WacePay)
        }

        return [
            'country' => $country,
            'currency' => ($c = $get(['currency', 'currencyCode', 'currency_code', 'country.currency'])) ? strtoupper(substr((string) $c, 0, 3)) : null,
            'payer_code' => ($c = $get(['payerCode', 'payer_code', 'code', 'id'])) !== null ? (string) $c : null,
            'payer_name' => ($n = $get(['payerName', 'payer_name', 'name', 'label', 'operator', 'operatorName'])) ? mb_substr((string) $n, 0, 150) : null,
            'method' => $method,
            'payin' => (bool) $payin,
            'payout' => (bool) $payout,
        ];
    }

    /** Crée / met à jour les pays absents de config/corridors.php. */
    protected function ensureCorridors(array $countries): array
    {
        $config = config('flashpay.corridors', []);
        $added = [];
        $skipped = [];
        foreach ($countries as $iso) {
            if (isset($config[$iso])) {
                continue; // pays PEEX existant : l'admin choisit le partenaire
            }
            $ref = CountryReference::COUNTRIES[$iso] ?? null;
            if (! $ref) {
                $skipped[] = $iso; // indicatif / longueur inconnus : à compléter
                continue;
            }
            $payers = WacepayCoverage::where('country', $iso)->get();
            $operators = $payers->where('method', 'wallet')->mapWithKeys(fn ($p) => [
                'wace-' . Str::slug($p->payer_code) => ['label' => $p->payer_name ?: $p->payer_code, 'rail' => 'digitwace', 'prefixes' => [], 'payer_code' => $p->payer_code],
            ])->all();

            $existing = CorridorCountry::find($iso);
            CorridorCountry::updateOrCreate(['country' => $iso], [
                'name' => $existing?->name ?? $ref[0],
                'zone' => $existing?->zone ?? 'INTERNATIONAL',
                'dial' => $existing?->dial ?? $ref[2],
                'local_length' => $existing?->local_length ?? $ref[3],
                'currency' => $payers->pluck('currency')->filter()->first() ?? $existing?->currency ?? $ref[4],
                'flag' => CountryReference::flag($iso),
                'operators' => $operators,
                'source' => 'wacepay',
            ]);

            $setting = CorridorSetting::firstOrNew(['country' => $iso]);
            if (! $setting->exists) {
                // Nouveau pays : ouvert selon les services WacePay, partenaire = WacePay
                $setting->fill([
                    'collect' => (bool) $payers->where('payin', true)->count(),
                    'payout' => (bool) $payers->where('payout', true)->count(),
                    'collect_partner' => 'digitwace',
                    'payout_partner' => 'digitwace',
                    'note' => 'Ajouté par la synchro WacePay',
                ])->save();
                $added[] = $iso;
            }
        }

        return [$added, $skipped];
    }

    /** Résumé par pays pour la console : services WacePay et payeurs. */
    public function byCountry(): array
    {
        try {
            return WacepayCoverage::orderBy('country')->get()->groupBy('country')->map(fn ($rows) => [
                'payin' => (bool) $rows->where('payin', true)->count(),
                'payout' => (bool) $rows->where('payout', true)->count(),
                'payers' => $rows->map(fn ($r) => ['code' => $r->payer_code, 'name' => $r->payer_name, 'method' => $r->method, 'payin' => $r->payin, 'payout' => $r->payout])->values(),
            ])->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
