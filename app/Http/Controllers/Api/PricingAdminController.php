<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CorridorSetting;
use App\Models\ExchangeRate;
use App\Models\Tariff;
use App\Models\Transaction;
use App\Services\Peex\PeexCorridors;
use Illuminate\Http\Request;

/**
 * Super Admin — Grille tarifaire (vue enrichie) et Pays & change
 * (activation des corridors depuis la console, taux de change).
 */
class PricingAdminController extends Controller
{
    public function __construct(protected PeexCorridors $corridors)
    {
    }

    /** Tarifs + indicateurs : paliers actifs, frais perçus 30 j par opération. */
    public function tariffs()
    {
        $since = now()->subDays(30);
        $typeToOp = ['atm_withdrawal' => 'atm', 'qr_payment' => 'merchant_payment', 'nfc_payment' => 'merchant_payment', 'manual_payment' => 'merchant_payment'];

        $fees = Transaction::where('status', 'successful')->where('created_at', '>=', $since)
            ->selectRaw('type, COUNT(*) as c, COALESCE(SUM(fee),0) as f, COALESCE(SUM(merchant_fee),0) as mf, COALESCE(SUM(amount),0) as v')
            ->groupBy('type')->get();
        $revenue = [];
        foreach ($fees as $r) {
            $op = $typeToOp[$r->type] ?? $r->type;
            $revenue[$op] = ['count' => ($revenue[$op]['count'] ?? 0) + (int) $r->c, 'fees' => ($revenue[$op]['fees'] ?? 0) + (int) $r->f, 'volume' => ($revenue[$op]['volume'] ?? 0) + (int) $r->v];
            if ($r->mf) {
                $revenue['merchant_fee'] = ['count' => ($revenue['merchant_fee']['count'] ?? 0) + (int) $r->c, 'fees' => ($revenue['merchant_fee']['fees'] ?? 0) + (int) $r->mf, 'volume' => ($revenue['merchant_fee']['volume'] ?? 0) + (int) $r->v];
            }
        }

        $all = Tariff::orderBy('operation_type')->orderBy('scope')->orderBy('min_amount')->get();

        return response()->json([
            'operations' => Tariff::OPERATIONS,
            'scopes' => Tariff::SCOPES,
            'tariffs' => $all,
            'revenue' => $revenue,
            'kpi' => [
                'tiers' => $all->count(),
                'active' => $all->where('active', true)->count(),
                'free_operations' => collect(array_keys(Tariff::OPERATIONS))->filter(fn ($op) => ! $all->where('operation_type', $op)->where('active', true)
                    ->contains(fn ($t) => $t->fee_value > 0 || $t->min_fee > 0))->count(),
                'fees_30d' => array_sum(array_column($revenue, 'fees')),
                'volume_30d' => (int) Transaction::where('status', 'successful')->where('created_at', '>=', $since)->sum('amount'),
            ],
        ]);
    }

    /** Active / désactive un palier sans le modifier. */
    public function toggleTariff(Request $request, Tariff $tariff)
    {
        $tariff->update(['active' => $request->boolean('active')]);
        return response()->json(['message' => $tariff->active ? 'Palier activé.' : 'Palier désactivé.']);
    }

    // ------------------------------------------------------------------ Corridors

    public function corridors()
    {
        $defaults = $this->corridors->defaults();
        $overrides = CorridorSetting::all()->keyBy('country');
        $since = now()->subDays(30);

        // Activité 30 j par pays de destination / source (meta)
        $usage = Transaction::where('created_at', '>=', $since)->whereNotNull('meta')->get(['meta', 'status', 'amount'])
            ->reduce(function ($acc, $t) {
                foreach (array_unique(array_filter([$t->meta['destination_country'] ?? null, $t->meta['source_country'] ?? null])) as $iso) {
                    $acc[$iso]['count'] = ($acc[$iso]['count'] ?? 0) + 1;
                    $acc[$iso]['volume'] = ($acc[$iso]['volume'] ?? 0) + ($t->status === 'successful' ? $t->amount : 0);
                }
                return $acc;
            }, []);

        $list = collect($this->corridors->catalog())->map(function ($c) use ($defaults, $overrides, $usage) {
            $d = $defaults[$c['country']] ?? [];
            $o = $overrides[$c['country']] ?? null;
            return $c + [
                'default_collect' => (bool) ($d['collect'] ?? false),
                'default_payout' => (bool) ($d['payout'] ?? false),
                'default_payout_api' => $d['payout_api'] ?? null,
                'overridden' => (bool) $o,
                'note' => $o?->note,
                'updated_at' => $o?->updated_at,
                'usage' => $usage[$c['country']] ?? ['count' => 0, 'volume' => 0],
            ];
        })->values();

        $rates = ExchangeRate::orderBy('base')->orderBy('quote')->get();

        return response()->json([
            'sandbox' => (bool) config('flashpay.peex.sandbox'),
            'corridors' => $list,
            'zones' => ['CEMAC' => 'XAF', 'UEMOA' => 'XOF', 'RDC' => 'CDF', 'GUINEE' => 'GNF'],
            'kpi' => [
                'countries' => $list->count(),
                'collect' => $list->where('collect', true)->count(),
                'payout' => $list->where('payout', true)->count(),
                'operators' => $list->sum(fn ($c) => count($c['operators'])),
                'rates' => $rates->count(),
                'stale_rates' => $rates->filter(fn ($r) => str_contains((string) $r->source, 'indicatif') || $r->updated_at < now()->subDays(7))->count(),
            ],
        ]);
    }

    /** { collect?: bool, payout?: bool, payout_api?: disbursement|remittance, note? } */
    public function updateCorridor(Request $request, string $iso)
    {
        $iso = strtoupper($iso);
        abort_unless(isset($this->corridors->defaults()[$iso]), 404, 'Pays inconnu.');
        $v = $request->validate([
            'collect' => 'sometimes|boolean',
            'payout' => 'sometimes|boolean',
            'payout_api' => 'sometimes|nullable|in:disbursement,remittance',
            'note' => 'sometimes|nullable|string|max:190',
        ]);

        CorridorSetting::updateOrCreate(['country' => $iso], $v + ['updated_by' => $request->user()->id]);
        PeexCorridors::flushOverrides();

        return response()->json(['message' => 'Corridor mis à jour.', 'corridor' => collect($this->corridors->catalog())->firstWhere('country', $iso)]);
    }

    /** Revient aux valeurs de config/corridors.php. */
    public function resetCorridor(string $iso)
    {
        CorridorSetting::where('country', strtoupper($iso))->delete();
        PeexCorridors::flushOverrides();
        return response()->json(['message' => 'Valeurs par défaut rétablies.']);
    }

    // ------------------------------------------------------------------ Taux

    public function toggleRate(Request $request, ExchangeRate $rate)
    {
        $rate->update(['active' => $request->boolean('active')]);
        return response()->json(['message' => $rate->active ? 'Taux activé.' : 'Taux désactivé.']);
    }

    public function deleteRate(ExchangeRate $rate)
    {
        $rate->delete();
        return response()->json(['message' => 'Taux supprimé.']);
    }
}
