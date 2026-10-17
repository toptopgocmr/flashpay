<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitTariff;
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

        return response()->json([
            'tariffs' => $tariffs,
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
        $overlap = RemitTariff::where('country', $v['country'])->where('business_type', $v['business_type'])->where('method', $v['method'])
            ->where('partner', $v['partner'])->where('active', true)
            ->when(! empty($v['id']), fn ($q) => $q->where('id', '!=', $v['id']))
            ->where('min_amount', '<=', $v['max_amount'])->where('max_amount', '>=', $v['min_amount'])->exists();
        if ($overlap && ($v['active'] ?? true)) {
            return response()->json(['message' => 'Cette tranche chevauche un palier existant pour les mêmes critères.', 'errors' => ['min_amount' => ['Tranche déjà couverte.']]], 422);
        }
        $t = ! empty($v['id']) ? tap(RemitTariff::findOrFail($v['id']))->update($v) : RemitTariff::create($v);
        \App\Support\Audit::log('remit_tariff.save', null, $t->only(['id', 'country', 'business_type', 'method', 'partner', 'min_amount', 'max_amount', 'fee_type', 'fee_value']), $request->user()->id);
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
            'fee' => $r['fee'], 'total' => (int) $v['amount'] + $r['fee'], 'partner' => $partner, 'partner_name' => $partner ? RemitCountries::partnerName($partner) : null,
            'tariff' => $r['tariff'], 'source' => $r['tariff'] ? 'grille internationale' : 'aucun palier : tarif « envoi d\'argent » international de la grille générale',
        ]);
    }
}
