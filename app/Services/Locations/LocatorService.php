<?php

namespace App\Services\Locations;

use App\Models\Agent;
use App\Models\ServicePoint;
use App\Support\Logos;
use Illuminate\Support\Str;

/**
 * Carte « Localiser un agent » : agents OfficePay validés (position renseignée),
 * agences, points de vente et GAB, triés du plus proche au plus éloigné.
 */
class LocatorService
{
    public const TYPES = ['agent' => 'Agent OfficePay', 'agency' => 'Agence OfficePay', 'point_of_sale' => 'Point de vente', 'atm' => 'GAB'];

    /**
     * @param string[] $types agent, agency, point_of_sale, atm (vide = tous)
     * @return array<int, array>
     */
    public function nearby(?float $lat, ?float $lng, array $types = [], ?string $q = null, ?string $country = null, float $radiusKm = 50, int $limit = 100): array
    {
        $types = array_values(array_intersect($types ?: array_keys(self::TYPES), array_keys(self::TYPES)));
        $items = [];

        if (in_array('agent', $types, true)) {
            $agents = Agent::with('user:id,full_name,phone,status')
                ->where('validation_status', 'approved')->where('show_on_map', true)
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->when($country, fn ($w) => $w->where(fn ($x) => $x->whereNull('country')->orWhere('country', $country)))
                ->get();
            foreach ($agents as $a) {
                if ($a->user && $a->user->status && $a->user->status !== 'active') {
                    continue; // compte désactivé ou bloqué : plus affiché
                }
                $items[] = [
                    'id' => 'agent:' . $a->id,
                    'type' => 'agent',
                    'type_label' => self::TYPES['agent'],
                    'name' => $a->user?->full_name ?: ('Agent ' . ($a->agent_code ?? $a->id)),
                    'code' => $a->agent_code,
                    'operator' => null,
                    'address' => $a->address,
                    'city' => $a->city,
                    'country' => $a->country,
                    'latitude' => (float) $a->latitude,
                    'longitude' => (float) $a->longitude,
                    'opening_hours' => $a->opening_hours,
                    'phone' => $a->user?->phone ? '+' . ltrim($a->user->phone, '+') : null,
                    'services' => ['cash_in', 'cash_out'],
                    'logo' => null,
                ];
            }
        }

        $pointTypes = array_values(array_intersect($types, array_keys(ServicePoint::TYPES)));
        if ($pointTypes) {
            $points = ServicePoint::where('active', true)->whereIn('type', $pointTypes)
                ->when($country, fn ($w) => $w->where('country', $country))->get();
            foreach ($points as $p) {
                $items[] = [
                    'id' => 'point:' . $p->id,
                    'type' => $p->type,
                    'type_label' => self::TYPES[$p->type] ?? $p->type,
                    'name' => $p->name,
                    'code' => null,
                    'operator' => $p->operator,
                    'address' => $p->address,
                    'city' => $p->city,
                    'country' => $p->country,
                    'latitude' => (float) $p->latitude,
                    'longitude' => (float) $p->longitude,
                    'opening_hours' => $p->opening_hours ?: ($p->type === 'atm' ? '24h/24' : null),
                    'phone' => $p->phone,
                    'services' => $p->services ?: ($p->type === 'atm' ? ['atm_withdrawal'] : ['cash_in', 'cash_out']),
                    'logo' => $p->type === 'atm' ? Logos::bank($p->operator ?: $p->name) : null,
                ];
            }
        }

        if ($q = trim((string) $q)) {
            $needle = Str::lower(Str::ascii($q));
            $items = array_values(array_filter($items, fn ($i) => str_contains(
                Str::lower(Str::ascii(implode(' ', [$i['name'], $i['operator'], $i['address'], $i['city'], $i['code']]))), $needle)));
        }

        foreach ($items as &$i) {
            $i['distance_km'] = ($lat !== null && $lng !== null) ? round(self::distanceKm($lat, $lng, $i['latitude'], $i['longitude']), 2) : null;
        }
        unset($i);

        if ($lat !== null && $lng !== null) {
            // Recherche par nom : on garde les résultats lointains ; sinon rayon autour de l'utilisateur
            if (! $q) {
                $items = array_values(array_filter($items, fn ($i) => $i['distance_km'] <= $radiusKm));
            }
            usort($items, fn ($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        } else {
            usort($items, fn ($a, $b) => strcmp((string) $a['city'] . $a['name'], (string) $b['city'] . $b['name']));
        }

        return array_slice($items, 0, $limit);
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * 6371.0 * asin(min(1, sqrt($a)));
    }
}
