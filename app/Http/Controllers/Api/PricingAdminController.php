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

        $coverage = app(\App\Services\Digitwace\CoverageService::class)->byCountry();
        $list = collect($this->corridors->catalog())->map(function ($c) use ($defaults, $overrides, $usage, $coverage) {
            $d = $defaults[$c['country']] ?? [];
            $o = $overrides[$c['country']] ?? null;
            return $c + [
                'default_collect' => (bool) ($d['collect'] ?? false),
                'default_payout' => (bool) ($d['payout'] ?? false),
                'default_payout_api' => $d['payout_api'] ?? null,
                'collect_partner_name' => PeexCorridors::partnerName($c['collect_partner']),
                // Services proposés par WacePay dans ce pays (synchro getPayerCode)
                'wacepay' => $coverage[$c['country']] ?? null,
                'payout_partner_name' => PeexCorridors::partnerName($c['payout_partner']),
                'overridden' => (bool) $o,
                'note' => $o?->note,
                'updated_at' => $o?->updated_at,
                'usage' => $usage[$c['country']] ?? ['count' => 0, 'volume' => 0],
            ];
        })->values();

        $rates = ExchangeRate::orderBy('base')->orderBy('quote')->get();

        $wace = app(\App\Services\Digitwace\DigitwaceClient::class);
        $partners = [
            [
                'key' => 'peex', 'name' => 'PEEX', 'flows' => ['collect', 'payout'],
                'ready' => (bool) config('flashpay.peex.secret_key') && (bool) config('flashpay.rails.peex.enabled'),
                'mode' => config('flashpay.peex.sandbox') ? 'Sandbox' : 'Production',
                'collect_countries' => $list->where('collect', true)->where('collect_partner', 'peex')->count(),
                'payout_countries' => $list->where('payout', true)->where('payout_partner', 'peex')->count(),
                'webhook' => url('/api/webhooks/peex/{service}'),
            ],
            [
                'key' => 'digitwace', 'name' => 'WacePay (Digitwace)', 'flows' => ['collect', 'payout'],
                'ready' => $wace->enabled() && (bool) config('flashpay.rails.digitwace.enabled'),
                'mode' => $wace->enabled() ? (config('flashpay.digitwace.sandbox', true) ? 'Sandbox' : 'Production') : 'clés API à saisir',
                'sandbox' => (bool) config('flashpay.digitwace.sandbox', true),
                // Collecte hors mobile money : cartes Visa / Mastercard et comptes bancaires (page WacePay)
                'card' => config('payment_methods.card_driver') === 'wacepay',
                'bank_debit' => config('payment_methods.bank_debit_driver') === 'wacepay',
                'collect_countries' => $list->where('collect', true)->where('collect_partner', 'digitwace')->count(),
                'payout_countries' => $list->where('payout', true)->where('payout_partner', 'digitwace')->count(),
                'coverage_countries' => count($coverage),
                'coverage_payin' => collect($coverage)->where('payin', true)->count(),
                'coverage_payout' => collect($coverage)->where('payout', true)->count(),
                'coverage_synced_at' => cache('wacepay:coverage:synced_at'),
                'webhook' => $wace->callbackUrl(),
            ],
        ];

        return response()->json([
            'sandbox' => (bool) config('flashpay.peex.sandbox'),
            'partners' => $partners,
            'corridors' => $list,
            'zones' => ['CEMAC' => 'XAF', 'UEMOA' => 'XOF', 'RDC' => 'CDF', 'GUINEE' => 'GNF']
                + ($list->where('zone', 'INTERNATIONAL')->count() ? ['INTERNATIONAL' => 'multi-devises'] : []),
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
            // Partenaire qui gère les flux : collecte = PEEX (seul partenaire de collecte), versement = PEEX ou WacePay
            'collect_partner' => 'sometimes|nullable|in:peex,digitwace',
            'payout_partner' => 'sometimes|nullable|in:peex,digitwace',
            'note' => 'sometimes|nullable|string|max:190',
        ]);

        // WacePay n'est proposé que là où la couverture synchronisée l'annonce
        foreach (['collect_partner' => 'payin', 'payout_partner' => 'payout'] as $field => $service) {
            if (($v[$field] ?? null) === 'digitwace') {
                $cov = app(\App\Services\Digitwace\CoverageService::class)->byCountry();
                $label = $service === 'payin' ? 'la collecte' : 'le versement';
                if (! $cov) {
                    return response()->json(['message' => 'Synchronisez d\'abord la couverture WacePay (carte WacePay en haut de la page) pour savoir où WacePay est disponible.'], 422);
                }
                if (empty($cov[$iso][$service])) {
                    return response()->json(['message' => "WacePay ne propose pas {$label} pour {$iso} (selon la couverture synchronisée)."], 422);
                }
            }
        }

        CorridorSetting::updateOrCreate(['country' => $iso], $v + ['updated_by' => $request->user()->id]);
        PeexCorridors::flushOverrides();
        \App\Support\Audit::log('corridor.update', null, ['country' => $iso] + $v, $request->user()->id);

        $waceOff = ! app(\App\Services\Digitwace\DigitwaceClient::class)->enabled();
        $message = match (true) {
            isset($v['payout_partner']) => 'Versements vers ' . $iso . ' gérés par ' . PeexCorridors::partnerName($v['payout_partner']) . '.'
                . ($v['payout_partner'] === 'digitwace' && $waceOff ? ' Attention : WacePay n\'est pas encore configuré, les envois vers ce pays seront refusés.' : ''),
            isset($v['collect_partner']) => 'Collecte depuis ' . $iso . ' gérée par ' . PeexCorridors::partnerName($v['collect_partner']) . '.'
                . ($v['collect_partner'] === 'digitwace' && $waceOff ? ' Attention : WacePay n\'est pas encore configuré, les recharges / paiements depuis ce pays seront refusés.' : ''),
            default => 'Corridor mis à jour.',
        };

        return response()->json(['message' => $message, 'corridor' => collect($this->corridors->catalog())->firstWhere('country', $iso)]);
    }

    /** Soldes du compte FlashPay chez WacePay (?refresh=1 force l'appel). */
    public function wacepayBalances(Request $request)
    {
        return response()->json(app(\App\Services\Digitwace\WacepayBalanceService::class)->balances($request->boolean('refresh')));
    }

    /** Diagnostic de la connexion WacePay (code HTTP réel, IP sortante, clés présentes). */
    public function diagnoseWacepay()
    {
        $d = app(\App\Services\Digitwace\DigitwaceClient::class)->diagnose();
        $d['server_ip'] = \App\Services\Digitwace\WacepayBalanceService::serverIp();
        return response()->json($d);
    }

    /** Détection automatique de l'adresse de connexion WacePay (enregistrée si trouvée). */
    public function discoverWacepay()
    {
        $r = app(\App\Services\Digitwace\DigitwaceClient::class)->discover();
        \App\Support\Audit::log('digitwace.discover', null, ['found' => $r['found']]);
        return response()->json($r);
    }

    /** Oublie l'adresse détectée : retour aux variables Railway (DIGITWACE_BASE_URL…). */
    public function resetWacepayEndpoint()
    {
        app(\App\Services\Ops\PlatformSettings::class)->set('digitwace_endpoint', null);
        return response()->json(['message' => 'Adresse WacePay détectée oubliée : les variables Railway s\'appliquent.']);
    }

    /** Synchronise la couverture WacePay (pays, opérateurs, collecte / versement) depuis l'API. */
    public function syncWacepay(Request $request)
    {
        $client = app(\App\Services\Digitwace\DigitwaceClient::class);
        if (! $client->enabled()) {
            return response()->json(['message' => 'WacePay n\'est pas configuré (DIGITWACE_ENABLED, clés API). Renseignez les variables Railway puis réessayez.'], 422);
        }
        try {
            \Illuminate\Support\Facades\Cache::forget('digitwace:payers:all');
            $r = app(\App\Services\Digitwace\CoverageService::class)->sync();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Synchronisation WacePay impossible : ' . $e->getMessage()], 502);
        }
        \App\Support\Audit::log('wacepay.coverage_sync', null, $r, $request->user()->id);

        return response()->json($r + ['message' => sprintf('Couverture WacePay : %d payeur(s) dans %d pays. %s%s',
            $r['payers'], $r['countries'],
            $r['added'] ? count($r['added']) . ' pays ajouté(s) : ' . implode(', ', $r['added']) . '. ' : 'Aucun nouveau pays. ',
            $r['skipped'] ? 'À compléter (indicatif inconnu) : ' . implode(', ', $r['skipped']) . '.' : '')]);
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
