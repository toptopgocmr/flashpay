<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitCurrency;
use App\Models\RemitTariff;
use App\Models\RemitTax;
use App\Models\RemitZone;
use App\Models\Transaction;
use App\Services\Remit\InternationalTransferService;
use App\Services\Remit\RemitCountries;
use App\Services\Remit\RemitFeeService;
use Illuminate\Http\Request;

/** Console › Tarifs internationaux : grille des transferts internationaux, à part de la grille nationale. */
class RemitTariffAdminController extends Controller
{
    public function index()
    {
        $since = now()->subDays(30);
        $tx = Transaction::where('type', 'international_transfer')->where('status', 'successful')->where('created_at', '>=', $since)->get(['amount', 'fee', 'meta']);
        $byCountry = [];
        foreach ($tx as $t) {
            $iso = strtoupper((string) ($t->meta['remit']['country'] ?? '?'));
            $byCountry[$iso]['count'] = ($byCountry[$iso]['count'] ?? 0) + 1;
            $byCountry[$iso]['fees'] = ($byCountry[$iso]['fees'] ?? 0) + (int) $t->fee;
            $byCountry[$iso]['volume'] = ($byCountry[$iso]['volume'] ?? 0) + (int) $t->amount;
        }
        $tariffs = RemitTariff::orderByRaw("country = '*' desc")->orderBy('country')->orderBy('business_type')->orderBy('method')->orderBy('min_amount')->get();

        $zones = $this->safe(fn () => RemitZone::orderBy('sort')->orderBy('name')->get(), collect());
        $taxes = $this->safe(fn () => RemitTax::orderBy('id')->get(), collect());

        return response()->json([
            'tariffs' => $tariffs,
            'zones' => $zones,
            'taxes' => $taxes,
            'currencies' => $this->safe(fn () => RemitCurrency::orderBy('code')->get(), collect()),
            'countries' => collect(RemitCountries::all())->map(fn ($c) => ['iso' => $c['iso'], 'name' => $c['name'], 'currency' => $c['currency']])->values(),
            'business_types' => collect(InternationalTransferService::BUSINESS_TYPES)->map(fn ($b) => $b['short'] . ' — ' . $b['label']),
            'methods' => InternationalTransferService::METHODS,
            'partners' => collect(RemitCountries::PARTNERS)->map(fn ($p) => $p['name']),
            'currency' => config('flashpay.remit.from_currency', 'XAF'),
            'revenue' => $byCountry,
            'kpi' => [
                'tiers' => $tariffs->count(),
                'active' => $tariffs->where('active', true)->count(),
                'countries' => $tariffs->where('active', true)->where('country', '!=', '*')->pluck('country')->unique()->count(),
                'fees_30d' => (int) $tx->sum('fee'),
                'volume_30d' => (int) $tx->sum('amount'),
                'count_30d' => $tx->count(),
            ],
        ]);
    }

    public function upsert(Request $request)
    {
        $v = $request->validate([
            'id' => 'nullable|exists:remit_tariffs,id',
            'zone_id' => 'nullable|exists:remit_zones,id',
            'country' => 'required|string|max:3',
            'business_type' => 'required|in:*,' . implode(',', array_keys(InternationalTransferService::BUSINESS_TYPES)),
            'method' => 'required|in:*,' . implode(',', array_keys(InternationalTransferService::METHODS)),
            'partner' => 'required|in:*,' . implode(',', array_keys(RemitCountries::PARTNERS)),
            'min_amount' => 'required|integer|min:0',
            'max_amount' => 'required|integer|gt:min_amount',
            'fee_type' => 'required|in:fixed,percent',
            'fee_value' => 'required|numeric|min:0|max:' . ($request->input('fee_type') === 'percent' ? 50 : 100000000),
            'min_fee' => 'nullable|integer|min:0',
            'max_fee' => 'nullable|integer|min:0',
            'active' => 'boolean',
            'label' => 'nullable|string|max:120',
        ], ['fee_value.max' => 'Un pourcentage ne peut pas dépasser 50 %.']);
        $v['country'] = strtoupper($v['country']);
        if ($v['country'] !== '*' && ! RemitCountries::get($v['country'])) {
            return response()->json(['message' => 'Pays inconnu.', 'errors' => ['country' => ['Pays inconnu.']]], 422);
        }
        $v['min_fee'] = $v['min_fee'] ?? 0;
        if (! empty($v['max_fee']) && $v['max_fee'] < $v['min_fee']) {
            return response()->json(['message' => 'Le plafond doit être supérieur au minimum.', 'errors' => ['max_fee' => ['Plafond < minimum.']]], 422);
        }
        // Deux paliers identiques qui se chevauchent : le plus précis ne peut pas être départagé
        $v['zone_id'] = $v['zone_id'] ?? null;
        $overlap = RemitTariff::where('country', $v['country'])->where('zone_id', $v['zone_id'])->where('business_type', $v['business_type'])->where('method', $v['method'])
            ->where('partner', $v['partner'])->where('active', true)
            ->when(! empty($v['id']), fn ($q) => $q->where('id', '!=', $v['id']))
            ->where('min_amount', '<=', $v['max_amount'])->where('max_amount', '>=', $v['min_amount'])->exists();
        if ($overlap && ($v['active'] ?? true)) {
            return response()->json(['message' => 'Cette tranche chevauche un palier existant pour les mêmes critères.', 'errors' => ['min_amount' => ['Tranche déjà couverte.']]], 422);
        }
        $t = ! empty($v['id']) ? tap(RemitTariff::findOrFail($v['id']))->update($v) : RemitTariff::create($v);
        \App\Support\Audit::log('remit_tariff.save', null, $t->only(['id', 'zone_id', 'country', 'business_type', 'method', 'partner', 'min_amount', 'max_amount', 'fee_type', 'fee_value']), $request->user()->id);
        return response()->json(['message' => 'Palier enregistré.', 'tariff' => $t], 201);
    }

    public function toggle(Request $request, RemitTariff $remitTariff)
    {
        $remitTariff->update(['active' => $request->boolean('active')]);
        return response()->json(['message' => $remitTariff->active ? 'Palier activé.' : 'Palier désactivé.']);
    }

    public function destroy(Request $request, RemitTariff $remitTariff)
    {
        \App\Support\Audit::log('remit_tariff.delete', null, $remitTariff->toArray(), $request->user()->id);
        $remitTariff->delete();
        return response()->json(['message' => 'Palier supprimé.']);
    }

    /** Simulateur : même calcul que l'app (palier le plus précis). */
    public function simulate(Request $request, RemitFeeService $fees)
    {
        $v = $request->validate(['country' => 'required|string|size:2', 'business_type' => 'required|string', 'method' => 'required|string', 'amount' => 'required|integer|min:1']);
        $partner = RemitCountries::partnerFor($v['country']);
        $r = $fees->compute($v['country'], $v['business_type'], $v['method'], $partner, (int) $v['amount']);
        return response()->json([
            'fee' => $r['fee'], 'fee_ht' => $r['fee_ht'], 'taxes' => $r['taxes'], 'taxes_total' => $r['taxes_total'],
            'zone' => $r['zone']?->name, 'threshold' => $r['threshold'], 'over_threshold' => $r['threshold'] && (int) $v['amount'] > $r['threshold'],
            'fx' => $this->safe(fn () => ($c = RemitCountries::get($v['country'])) ? $fees->convert((int) $v['amount'], config('flashpay.remit.from_currency', 'XAF'), $c['currency']) : null, null),
            'total' => (int) $v['amount'] + $r['fee'], 'partner' => $partner, 'partner_name' => $partner ? RemitCountries::partnerName($partner) : null,
            'tariff' => $r['tariff'], 'source' => $r['tariff'] ? 'grille internationale' : 'aucun palier : tarif « envoi d\'argent » international de la grille générale',
        ]);
    }

    // ------------------------------------------------------------ Zones

    public function zoneUpsert(Request $request)
    {
        $v = $request->validate([
            'id' => 'nullable|exists:remit_zones,id',
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:190',
            'threshold' => 'nullable|integer|min:0',
            'countries' => 'array',
            'countries.*' => 'string|size:2',
            'is_default' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:999',
        ]);
        $v['countries'] = array_values(array_unique(array_map('strtoupper', $v['countries'] ?? [])));
        $v['sort'] = $v['sort'] ?? 0;
        // Un pays n'appartient qu'à une zone
        $others = RemitZone::when(! empty($v['id']), fn ($q) => $q->where('id', '!=', $v['id']))->get();
        foreach ($others as $z) {
            $dup = array_intersect($v['countries'], (array) $z->countries);
            if ($dup) {
                return response()->json(['message' => implode(', ', $dup) . ' : déjà dans la zone ' . $z->name . '.', 'errors' => ['countries' => ['Pays déjà classé.']]], 422);
            }
        }
        if (! empty($v['is_default'])) {
            RemitZone::where('is_default', true)->when(! empty($v['id']), fn ($q) => $q->where('id', '!=', $v['id']))->update(['is_default' => false]);
        }
        $z = ! empty($v['id']) ? tap(RemitZone::findOrFail($v['id']))->update($v) : RemitZone::create($v);
        \App\Support\Audit::log('remit_zone.save', null, $z->only(['id', 'name', 'threshold', 'countries', 'is_default']), $request->user()->id);
        return response()->json(['message' => 'Zone enregistrée.', 'zone' => $z], 201);
    }

    public function zoneDestroy(Request $request, RemitZone $remitZone)
    {
        if (RemitTariff::where('zone_id', $remitZone->id)->exists()) {
            return response()->json(['message' => 'Des paliers de la grille utilisent cette zone : supprimez-les ou changez leur zone d\'abord.'], 422);
        }
        \App\Support\Audit::log('remit_zone.delete', null, $remitZone->toArray(), $request->user()->id);
        $remitZone->delete();
        return response()->json(['message' => 'Zone supprimée.']);
    }

    // ------------------------------------------------------------ Taxes

    public function taxUpsert(Request $request)
    {
        $v = $request->validate([
            'id' => 'nullable|exists:remit_taxes,id',
            'code' => 'required|string|max:20|alpha_dash',
            'label' => 'required|string|max:100',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0|max:' . ($request->input('type') === 'percent' ? 100 : 100000000),
            'base' => 'required|in:amount,fee,none',
            'zone_id' => 'nullable|exists:remit_zones,id',
            'active' => 'boolean',
        ]);
        $v['code'] = strtoupper($v['code']);
        if ($v['type'] === 'fixed') {
            $v['base'] = 'none';
        } elseif ($v['base'] === 'none') {
            return response()->json(['message' => 'Une taxe en pourcentage s\'applique au montant ou aux frais.', 'errors' => ['base' => ['Assiette requise.']]], 422);
        }
        if (RemitTax::where('code', $v['code'])->when(! empty($v['id']), fn ($q) => $q->where('id', '!=', $v['id']))->exists()) {
            return response()->json(['message' => 'Ce code existe déjà.', 'errors' => ['code' => ['Code déjà utilisé.']]], 422);
        }
        $t = ! empty($v['id']) ? tap(RemitTax::findOrFail($v['id']))->update($v) : RemitTax::create($v);
        \App\Support\Audit::log('remit_tax.save', null, $t->only(['id', 'code', 'type', 'value', 'base', 'zone_id', 'active']), $request->user()->id);
        return response()->json(['message' => 'Taxe enregistrée.', 'tax' => $t], 201);
    }

    public function taxDestroy(Request $request, RemitTax $remitTax)
    {
        \App\Support\Audit::log('remit_tax.delete', null, $remitTax->toArray(), $request->user()->id);
        $remitTax->delete();
        return response()->json(['message' => 'Taxe supprimée.']);
    }

    // ------------------------------------------------------------ Taux de change (à partir du Congo)

    public function currencyUpsert(Request $request)
    {
        $v = $request->validate([
            'id' => 'nullable|exists:remit_currencies,id',
            'code' => 'required|string|size:3|alpha',
            'symbol' => 'nullable|string|max:12',
            'xaf_value' => 'required|numeric|gt:0|max:100000000',
            'active' => 'boolean',
        ]);
        $v['code'] = strtoupper($v['code']);
        if (RemitCurrency::where('code', $v['code'])->when(! empty($v['id']), fn ($q) => $q->where('id', '!=', $v['id']))->exists()) {
            return response()->json(['message' => 'Cette devise existe déjà.', 'errors' => ['code' => ['Devise déjà listée.']]], 422);
        }
        $c = ! empty($v['id']) ? tap(RemitCurrency::findOrFail($v['id']))->update($v) : RemitCurrency::create($v);
        \App\Support\Audit::log('remit_currency.save', null, $c->only(['id', 'code', 'xaf_value', 'active']), $request->user()->id);
        return response()->json(['message' => 'Taux enregistré.', 'currency' => $c], 201);
    }

    public function currencyDestroy(Request $request, RemitCurrency $remitCurrency)
    {
        \App\Support\Audit::log('remit_currency.delete', null, $remitCurrency->toArray(), $request->user()->id);
        $remitCurrency->delete();
        return response()->json(['message' => 'Devise supprimée.']);
    }

    protected function safe(callable $fn, $default)
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $default;
        }
    }
}
