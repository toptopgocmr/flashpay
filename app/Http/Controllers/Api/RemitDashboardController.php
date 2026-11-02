<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemitRequest;
use App\Services\Digitwace\WacepayBalanceService;
use App\Services\Fincra\FincraClient;
use App\Services\Remit\InternationalTransferService;
use App\Services\Remit\PawapayClient;
use App\Services\Remit\RemitCountries;
use App\Services\Remit\RemitStatusHandler;
use App\Services\Remit\TerrapayClient;
use App\Services\Remit\WacepayRemitClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Console › Remittance : tableau de bord des partenaires de transfert international
 * (Digitwace / WacePay v2, TerraPay, PawaPay, Fincra) — soldes, configuration, activité, opérations.
 */
class RemitDashboardController extends Controller
{
    public const PARTNERS = ['wacepay_remit', 'terrapay', 'pawapay', 'fincra_remit'];

    public function index(Request $request, InternationalTransferService $svc)
    {
        $fresh = $request->boolean('refresh');
        $since = now()->subDays(30);

        // Activité 30 jours par partenaire (opérations envoyées au partenaire)
        $rows = RemitRequest::with('transaction:id,reference,amount,fee,currency,destination_amount,destination_currency,meta,status')
            ->whereIn('partner', self::PARTNERS)->where('created_at', '>=', $since)->get();
        $stats = [];
        foreach (self::PARTNERS as $key) {
            $mine = $rows->where('partner', $key);
            $ok = $mine->where('status', 'successful');
            $stats[$key] = [
                'total' => $mine->count(),
                'successful' => $ok->count(),
                'failed' => $mine->where('status', 'failed')->count(),
                'pending' => $mine->whereIn('status', ['new', 'pending'])->count(),
                'volume' => (int) $ok->sum(fn ($r) => (int) ($r->transaction->amount ?? 0)),
                'fees' => (int) $ok->sum(fn ($r) => (int) ($r->transaction->fee ?? 0)),
                'success_rate' => ($d = $ok->count() + $mine->where('status', 'failed')->count()) ? round(100 * $ok->count() / $d, 1) : null,
            ];
        }

        $countries = collect(RemitCountries::all())->keys()->mapWithKeys(fn ($iso) => [$iso => RemitCountries::partnerFor($iso)]);
        $balances = $this->balances($fresh);

        $partners = [];
        foreach (self::PARTNERS as $key) {
            $client = $this->client($key);
            $assigned = $countries->filter(fn ($p) => $p === $key)->keys()->values();
            $partners[] = [
                'key' => $key,
                'name' => RemitCountries::partnerName($key),
                'methods' => RemitCountries::PARTNERS[$key]['methods'] ?? [],
                'configured' => $client->enabled(),
                'ready' => $svc->partnerReady($key),
                'mode' => $client->enabled() ? ($client->sandbox() ? 'sandbox' : 'production') : null,
                'base_url' => (string) config($this->cfgPrefix($key) . '.base_url'),
                'config' => $this->configRows($key),
                'balance' => $balances[$key] ?? null,
                'stats' => $stats[$key],
                'countries' => $assigned->all(),
                'countries_count' => $assigned->count(),
                'portal' => self::PORTALS[$key] ?? null,
            ];
        }

        $recent = RemitRequest::with('transaction:id,reference,amount,fee,currency,destination_amount,destination_currency,meta,status')
            ->whereIn('partner', self::PARTNERS)->latest('id')->limit(40)->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'created_at' => $r->created_at?->toIso8601String(),
                'partner' => $r->partner,
                'partner_name' => RemitCountries::partnerName($r->partner),
                'reference' => $r->reference,
                'partner_ref' => $r->partner_ref,
                'method' => $r->method,
                'business_type' => $r->business_type,
                'status' => $r->status,
                'raw_status' => $r->raw_status,
                'message' => $r->message,
                'final' => (bool) $r->finalized_at,
                'country' => $r->transaction->meta['destination_country'] ?? null,
                'transaction' => $r->transaction ? [
                    'id' => $r->transaction->id, 'reference' => $r->transaction->reference,
                    'amount' => (int) $r->transaction->amount, 'fee' => (int) $r->transaction->fee, 'currency' => $r->transaction->currency,
                    'destination_amount' => $r->transaction->destination_amount, 'destination_currency' => $r->transaction->destination_currency,
                ] : null,
            ]);

        $all = collect($stats);
        return response()->json([
            'server_ip' => WacepayBalanceService::serverIp(),
            'from' => ['country' => config('flashpay.remit.from_country'), 'currency' => config('flashpay.remit.from_currency')],
            'limits' => ['min' => (int) config('flashpay.remit.min_amount'), 'max' => (int) config('flashpay.remit.max_amount'), 'min_kyc_tier' => (int) config('flashpay.remit.min_kyc_tier')],
            'kpi' => [
                'partners_ready' => collect($partners)->where('ready', true)->count(),
                'partners' => count($partners),
                'countries_open' => $countries->filter(fn ($p) => $p && collect($partners)->firstWhere('key', $p)['ready'])->count(),
                'countries' => $countries->count(),
                'operations' => $all->sum('total'),
                'successful' => $all->sum('successful'),
                'failed' => $all->sum('failed'),
                'pending' => $all->sum('pending'),
                'volume' => $all->sum('volume'),
                'fees' => $all->sum('fees'),
            ],
            'partners' => $partners,
            'recent' => $recent,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /** Test de connexion d'un partenaire (authentification + solde quand l'API le permet). */
    public function test(string $partner)
    {
        abort_unless(in_array($partner, self::PARTNERS, true), 404);
        $client = $this->client($partner);
        $name = RemitCountries::partnerName($partner);
        if (! $client->enabled()) {
            return response()->json(['ok' => false, 'message' => "{$name} n'est pas configuré : " . implode(', ', $this->missing($partner)) . '.']);
        }
        $t = microtime(true);
        try {
            $msg = match ($partner) {
                'wacepay_remit' => (function () use ($client) {
                    $client->token(fresh: true);
                    $b = $client->balance();
                    return 'Connexion OK — solde ' . number_format($b['balance'], 0, ',', ' ') . ' ' . $b['currency'] . '.';
                })(),
                'pawapay' => 'Connexion OK — ' . count($client->balances()) . ' solde(s) pays.',
                'fincra_remit' => 'Connexion OK — ' . count($client->balances()) . ' solde(s).',
                // TerraPay n'expose pas de solde : on vérifie l'accès avec un statut de compte fictif
                'terrapay' => (function () use ($client) {
                    $r = $client->accountStatus(['method' => 'wallet', 'msisdn' => '+254700000000'], 'Test OfficePay');
                    return 'TerraPay répond (statut de compte test : ' . ($r['status'] ?? '?') . ').';
                })(),
            };
            Cache::forget('remit:dashboard:balances');
            return response()->json(['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000), 'message' => "{$name} : {$msg}"]);
        } catch (\Throwable $e) {
            $msg = "{$name} : " . mb_substr($e->getMessage(), 0, 300);
            $ips = null;
            // Accès refusé par liste blanche d'IP : on relève les IP de sortie réelles du serveur
            if (preg_match('/4001|restricted|whitelist|not allowed|forbidden/i', $e->getMessage())) {
                $ips = self::egressIps();
                $msg .= ' — Accès refusé par la liste blanche d\'IP du partenaire. IP de sortie relevées maintenant : '
                    . ($ips ? implode(', ', $ips) : 'inconnues')
                    . (count($ips) > 1 ? ' (plusieurs IP : activez une IP de sortie fixe sur Railway, ou faites autoriser toutes ces IP).' : ' (à faire autoriser par le partenaire).');
            }
            return response()->json(['ok' => false, 'ms' => (int) round((microtime(true) - $t) * 1000), 'message' => $msg, 'egress_ips' => $ips]);
        }
    }

    /** IP de sortie réellement utilisées (plusieurs relevés, sans cache) — utile quand Railway change d'IP. */
    public static function egressIps(int $samples = 5): array
    {
        $ips = [];
        foreach (array_slice(['https://api.ipify.org', 'https://api64.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com', 'https://api.ipify.org'], 0, $samples) as $url) {
            try {
                $ip = trim(\Illuminate\Support\Facades\Http::timeout(4)->withHeaders(['Connection' => 'close'])->get($url)->body());
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[$ip] = true;
                }
            } catch (\Throwable) {
            }
        }
        return array_keys($ips);
    }

    /** Console : relevé des IP de sortie du serveur. */
    public function egress()
    {
        return response()->json(['ips' => self::egressIps(), 'cached' => WacepayBalanceService::serverIp()]);
    }

    /** Relit le statut d'une opération chez le partenaire. */
    public function refresh(RemitRequest $remitRequest, RemitStatusHandler $handler)
    {
        abort_unless(in_array($remitRequest->partner, self::PARTNERS, true), 404);
        $req = $handler->refresh($remitRequest);
        return response()->json(['status' => $req->status, 'raw_status' => $req->raw_status, 'message' => $req->message, 'final' => (bool) $req->finalized_at]);
    }

    // ------------------------------------------------------------------

    protected const PORTALS = [
        'wacepay_remit' => 'https://sandbox.wacepay.com',
        'terrapay' => 'https://www.terrapay.com',
        'pawapay' => 'https://dashboard.sandbox.pawapay.io',
        'fincra_remit' => 'https://app.fincra.com',
    ];

    protected function client(string $key)
    {
        return match ($key) {
            'wacepay_remit' => app(WacepayRemitClient::class),
            'terrapay' => app(TerrapayClient::class),
            'pawapay' => app(PawapayClient::class),
            'fincra_remit' => app(FincraClient::class),
        };
    }

    protected function cfgPrefix(string $key): string
    {
        return 'flashpay.' . ['wacepay_remit' => 'wacepay_remit', 'terrapay' => 'terrapay', 'pawapay' => 'pawapay', 'fincra_remit' => 'fincra'][$key];
    }

    /** [env, clé de config, type: flag|secret|text|email, obligatoire, aide] */
    protected function spec(string $key): array
    {
        return match ($key) {
            'wacepay_remit' => [
                ['WACEPAY_REMIT_ENABLED', 'enabled', 'flag', true, 'active la remittance Digitwace'],
                ['WACEPAY_REMIT_BASE_URL', 'base_url', 'text', false, 'sandbox : https://sandbox.wacepay.com/api/v1'],
                ['WACEPAY_REMIT_EMAIL', 'email', 'email', true, 'identifiant du compte agence WacePay'],
                ['WACEPAY_REMIT_PASSWORD', 'password', 'secret', true, 'mot de passe du compte agence'],
                ['WACEPAY_REMIT_AUTO_CONFIRM', 'auto_confirm', 'flag', false, 'confirme les opérations « WAITING CONFIRMATION »'],
                ['WACEPAY_REMIT_CHECK_BALANCE', 'check_balance', 'flag', false, 'vérifie le solde avant chaque envoi'],
            ],
            'terrapay' => [
                ['TERRAPAY_ENABLED', 'enabled', 'flag', true, 'active TerraPay'],
                ['TERRAPAY_BASE_URL', 'base_url', 'text', false, 'UAT : https://uat-connect.terrapay.com:21211'],
                ['TERRAPAY_USERNAME', 'username', 'email', true, 'en-tête X-USERNAME'],
                ['TERRAPAY_PASSWORD', 'password', 'secret', true, 'en-tête X-PASSWORD (haché SHA-256)'],
                ['TERRAPAY_VERIFY_ACCOUNT', 'verify_account', 'flag', false, 'vérifie le compte du bénéficiaire avant débit'],
            ],
            'pawapay' => [
                ['PAWAPAY_ENABLED', 'enabled', 'flag', true, 'active PawaPay'],
                ['PAWAPAY_BASE_URL', 'base_url', 'text', false, 'sandbox : https://api.sandbox.pawapay.io'],
                ['PAWAPAY_API_TOKEN', 'token', 'secret', true, 'tableau de bord pawaPay › API Tokens'],
                ['PAWAPAY_MODE', 'mode', 'text', false, 'remittance (KYC expéditeur) ou payout'],
            ],
            'fincra_remit' => [
                ['FINCRA_ENABLED', 'enabled', 'flag', true, 'active Fincra'],
                ['FINCRA_BASE_URL', 'base_url', 'text', false, 'sandbox : https://sandboxapi.fincra.com'],
                ['FINCRA_SECRET_KEY', 'secret_key', 'secret', true, 'portail Fincra › API keys'],
                ['FINCRA_BUSINESS_ID', 'business_id', 'email', true, 'identifiant entreprise'],
                ['FINCRA_WEBHOOK_SECRET', 'webhook_secret', 'secret', false, 'signature des webhooks'],
                ['FINCRA_REMIT_SOURCE_CURRENCY', 'remit_source_currency', 'text', false, 'devise du solde débité (vide = devise du bénéficiaire)'],
            ],
        };
    }

    protected function configRows(string $key): array
    {
        $prefix = $this->cfgPrefix($key);
        return array_map(function ($s) use ($prefix) {
            [$env, $k, $type, $required, $help] = $s;
            $v = config("{$prefix}.{$k}");
            $present = $type === 'flag' ? (bool) $v : ($v !== null && $v !== '');
            $display = match ($type) {
                'flag' => $v ? 'true' : 'false',
                'secret' => $present ? 'Présent' : 'Absent',
                'email' => $present ? self::mask((string) $v) : '—',
                default => $present ? (string) $v : '—',
            };
            return ['env' => $env, 'type' => $type, 'required' => $required, 'present' => $present, 'value' => $display, 'help' => $help];
        }, $this->spec($key));
    }

    protected function missing(string $key): array
    {
        return collect($this->configRows($key))->filter(fn ($r) => $r['required'] && ! $r['present'])->pluck('env')->all();
    }

    /** a****@domaine.com / ab****yz */
    public static function mask(string $v): string
    {
        if (str_contains($v, '@')) {
            [$u, $d] = explode('@', $v, 2);
            return mb_substr($u, 0, 2) . str_repeat('•', max(2, mb_strlen($u) - 2)) . '@' . $d;
        }
        return mb_strlen($v) <= 6 ? str_repeat('•', mb_strlen($v)) : mb_substr($v, 0, 3) . '••••' . mb_substr($v, -3);
    }

    /** Soldes chez chaque partenaire (cache 2 min, « Actualiser » force la relecture). */
    protected function balances(bool $fresh): array
    {
        if ($fresh) {
            Cache::forget('remit:dashboard:balances');
        }
        return Cache::remember('remit:dashboard:balances', now()->addMinutes(2), function () {
            $out = [];
            foreach (self::PARTNERS as $key) {
                $client = $this->client($key);
                if (! $client->enabled()) {
                    $out[$key] = ['ok' => false, 'configured' => false, 'error' => 'Non configuré', 'accounts' => []];
                    continue;
                }
                try {
                    $accounts = match ($key) {
                        'wacepay_remit' => (fn ($b) => [['currency' => $b['currency'], 'balance' => $b['balance']]])($client->balance()),
                        'pawapay' => array_map(fn ($b) => ['currency' => $b['currency'], 'balance' => $b['balance'], 'country' => $b['country']], $client->balances()),
                        'fincra_remit' => array_map(fn ($b) => ['currency' => $b['currency'], 'balance' => $b['available']], $client->balances()),
                        'terrapay' => null,
                    };
                    $out[$key] = $accounts === null
                        ? ['ok' => true, 'configured' => true, 'unsupported' => true, 'accounts' => [], 'error' => 'Solde non exposé par l\'API TerraPay (voir le portail).']
                        : ['ok' => true, 'configured' => true, 'accounts' => $accounts];
                } catch (\Throwable $e) {
                    $out[$key] = ['ok' => false, 'configured' => true, 'error' => mb_substr($e->getMessage(), 0, 300), 'accounts' => []];
                }
            }
            return $out;
        });
    }
}
