<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Ops\PlatformSettings;
use App\Services\Remit\InternationalTransferService;
use App\Services\Remit\PawapayClient;
use App\Services\Remit\RemitCountries;
use App\Services\Remit\TerrapayClient;
use App\Services\Remit\WacepayRemitClient;
use Illuminate\Http\Request;

/**
 * Console › Pays & change › International : partenaires de remittance
 * (WacePay API v2, TerraPay, PawaPay) et partenaire choisi par pays de destination.
 */
class RemitAdminController extends Controller
{
    public function overview(InternationalTransferService $svc)
    {
        $since = now()->subDays(30);
        $usage = Transaction::where('type', 'international_transfer')->where('created_at', '>=', $since)->get(['meta', 'status', 'amount', 'destination_rail'])
            ->reduce(function ($acc, $t) {
                $iso = $t->meta['destination_country'] ?? null;
                if ($iso) {
                    $acc['countries'][$iso]['count'] = ($acc['countries'][$iso]['count'] ?? 0) + 1;
                    $acc['countries'][$iso]['volume'] = ($acc['countries'][$iso]['volume'] ?? 0) + ($t->status === 'successful' ? (int) $t->amount : 0);
                }
                $acc['partners'][$t->destination_rail] = ($acc['partners'][$t->destination_rail] ?? 0) + 1;
                return $acc;
            }, ['countries' => [], 'partners' => []]);

        $overrides = RemitCountries::overrides();
        $countries = collect(RemitCountries::all())->map(function ($c, $iso) use ($overrides, $usage) {
            $effective = RemitCountries::partnerFor($iso);
            return $c + [
                'partner' => $effective,
                'partner_name' => $effective ? RemitCountries::partnerName($effective) : null,
                'default_partner' => RemitCountries::defaultPartnerFor($iso),
                'closed' => ($overrides[$iso] ?? null) === 'off',
                'overridden' => array_key_exists($iso, $overrides),
                'available' => $this->availableFor($iso),
                'methods' => RemitCountries::methodsOf($effective),
                'usage' => $usage['countries'][$iso] ?? ['count' => 0, 'volume' => 0],
            ];
        })->values();

        $wace = app(WacepayRemitClient::class);
        $terra = app(TerrapayClient::class);
        $pawa = app(PawapayClient::class);
        $fincra = app(\App\Services\Fincra\FincraClient::class);
        $info = [
            'wacepay_remit' => ['client' => $wace, 'env' => ['WACEPAY_REMIT_ENABLED', 'WACEPAY_REMIT_EMAIL', 'WACEPAY_REMIT_PASSWORD'],
                'missing' => array_keys(array_filter(['WACEPAY_REMIT_ENABLED' => ! config('flashpay.wacepay_remit.enabled'), 'WACEPAY_REMIT_EMAIL' => ! config('flashpay.wacepay_remit.email'), 'WACEPAY_REMIT_PASSWORD' => ! config('flashpay.wacepay_remit.password')])),
                'note' => 'Wallet, banque et retrait cash · 120+ pays'],
            'terrapay' => ['client' => $terra,
                'missing' => array_keys(array_filter(['TERRAPAY_ENABLED' => ! config('flashpay.terrapay.enabled'), 'TERRAPAY_USERNAME' => ! config('flashpay.terrapay.username'), 'TERRAPAY_PASSWORD' => ! config('flashpay.terrapay.password')])),
                'note' => 'Wallet et banque · vérification du compte avant envoi'],
            'pawapay' => ['client' => $pawa,
                'missing' => array_keys(array_filter(['PAWAPAY_ENABLED' => ! config('flashpay.pawapay.enabled'), 'PAWAPAY_API_TOKEN' => ! config('flashpay.pawapay.token')])),
                'note' => 'Mobile money en Afrique · mode ' . $pawa->mode()],
            'fincra_remit' => ['client' => $fincra,
                'missing' => array_keys(array_filter(['FINCRA_ENABLED' => ! config('flashpay.fincra.enabled'), 'FINCRA_SECRET_KEY' => ! config('flashpay.fincra.secret_key'), 'FINCRA_BUSINESS_ID' => ! config('flashpay.fincra.business_id')])),
                'note' => 'Mobile money et banque · Afrique, Europe, Amériques' . (config('flashpay.fincra.remit_source_currency') ? ' · solde ' . config('flashpay.fincra.remit_source_currency') . ' converti' : '')],
        ];
        $partners = collect(RemitCountries::PARTNERS)->map(fn ($p, $key) => [
            'key' => $key,
            'name' => $p['name'],
            'methods' => $p['methods'],
            'ready' => $svc->partnerReady($key),
            'mode' => $info[$key]['client']->enabled() ? ($info[$key]['client']->sandbox() ? 'Sandbox' : 'Production') : 'clés API à saisir',
            'missing' => $info[$key]['missing'],
            'note' => $info[$key]['note'],
            'countries' => $countries->where('partner', $key)->count(),
            'operations_30d' => $usage['partners'][$key] ?? 0,
        ])->values();

        return response()->json([
            'partners' => $partners,
            'countries' => $countries,
            'default' => (array) config('flashpay.remit.partners', []),
            'kpi' => [
                'countries' => $countries->count(),
                'open' => $countries->whereNotNull('partner')->filter(fn ($c) => collect($partners)->firstWhere('key', $c['partner'])['ready'] ?? false)->count(),
                'operations_30d' => array_sum($usage['partners']),
            ],
        ]);
    }

    /** { partner: wacepay_remit | terrapay | pawapay | fincra_remit | off | default } */
    public function updateCountry(Request $request, PlatformSettings $settings, string $iso)
    {
        $iso = strtoupper($iso);
        abort_unless(RemitCountries::get($iso), 404, 'Pays inconnu.');
        $v = $request->validate(['partner' => 'required|in:wacepay_remit,terrapay,pawapay,fincra_remit,off,default']);
        if (! in_array($v['partner'], ['off', 'default'], true) && ! in_array($v['partner'], $this->availableFor($iso), true)) {
            return response()->json(['message' => RemitCountries::partnerName($v['partner']) . " ne couvre pas {$iso}."], 422);
        }
        $map = RemitCountries::overrides();
        if ($v['partner'] === 'default') {
            unset($map[$iso]);
        } else {
            $map[$iso] = $v['partner'];
        }
        $settings->set('remit_partners', $map, $request->user()->id);
        \App\Support\Audit::log('remit.country_partner', null, ['country' => $iso, 'partner' => $v['partner']], $request->user()->id);

        $name = RemitCountries::get($iso)['name'];
        $p = RemitCountries::partnerFor($iso);
        $ready = $p ? app(InternationalTransferService::class)->partnerReady($p) : false;
        return response()->json(['message' => match (true) {
            $v['partner'] === 'off' => "Transferts internationaux vers {$name} fermés.",
            ! $p => "Aucun partenaire pour {$name} : transferts fermés.",
            default => "Transferts vers {$name} confiés à " . RemitCountries::partnerName($p) . '.' . ($ready ? '' : ' Attention : ce partenaire n\'est pas encore configuré, les envois seront refusés.'),
        }]);
    }

    /** Soldes chez les partenaires (WacePay : agence ; PawaPay : par pays). */
    public function balances()
    {
        $out = [];
        $wace = app(WacepayRemitClient::class);
        if ($wace->enabled()) {
            try {
                $b = $wace->balance();
                $out['wacepay_remit'] = ['ok' => true, 'accounts' => [['currency' => $b['currency'], 'balance' => $b['balance']]]];
            } catch (\Throwable $e) {
                $out['wacepay_remit'] = ['ok' => false, 'error' => $e->getMessage(), 'accounts' => []];
            }
        }
        $pawa = app(PawapayClient::class);
        if ($pawa->enabled()) {
            try {
                $out['pawapay'] = ['ok' => true, 'accounts' => $pawa->balances()];
            } catch (\Throwable $e) {
                $out['pawapay'] = ['ok' => false, 'error' => $e->getMessage(), 'accounts' => []];
            }
        }
        $fincra = app(\App\Services\Fincra\FincraClient::class);
        if ($fincra->enabled()) {
            try {
                $out['fincra_remit'] = ['ok' => true, 'accounts' => array_map(fn ($b) => ['currency' => $b['currency'], 'balance' => $b['available']], $fincra->balances())];
            } catch (\Throwable $e) {
                $out['fincra_remit'] = ['ok' => false, 'error' => $e->getMessage(), 'accounts' => []];
            }
        }
        return response()->json($out);
    }

    /** Partenaires capables de payer ce pays. */
    protected function availableFor(string $iso): array
    {
        $out = ['wacepay_remit', 'terrapay'];
        if (PawapayClient::iso3($iso) && ! in_array($iso, ['FR', 'US', 'BE', 'GB'], true)) {
            $out[] = 'pawapay';
        }
        if (in_array(strtoupper($iso), app(\App\Services\Fincra\FincraClient::class)->remitCountries(), true)) {
            $out[] = 'fincra_remit';
        }
        return $out;
    }
}
