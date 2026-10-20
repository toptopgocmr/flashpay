<?php

namespace App\Services\Payments;

use App\Services\Peex\PeexCorridors;
use Illuminate\Support\Facades\Log;

/**
 * Routage des passerelles par corridor (pays) et par flux (collecte / versement).
 *
 * Modes (console › Pays & change, colonne « Routage ») :
 *  - fixed (Manuel)       : uniquement la passerelle choisie, jamais de bascule ;
 *  - mixed (Mixte)        : la passerelle choisie, puis 1 ou 2 passerelles de secours
 *                           choisies par l'équipe, dans cet ordre ;
 *  - auto  (Automatique)  : la passerelle choisie, puis toute autre passerelle active
 *                           qui couvre le pays (ordre FLASHPAY_FAILOVER_ORDER).
 *
 * La bascule n'a lieu que si le refus est CERTAIN (rejet explicite de l'API, données
 * refusées, opération introuvable chez le partenaire) : rien n'a été débité ni versé,
 * on peut donc réessayer ailleurs sans risque de double débit / double paiement.
 */
class GatewayRouting
{
    /** Clé « corridor » → rail du Switch */
    public const RAIL = ['peex' => 'peex', 'digitwace' => 'digitwace', 'pawapay' => 'pawapay_momo', 'maishapay' => 'maishapay', 'fincra' => 'fincra'];

    public const MODES = ['fixed' => 'Manuel', 'mixed' => 'Mixte', 'auto' => 'Automatique'];

    public function __construct(protected PeexCorridors $corridors)
    {
    }

    public static function partnerOf(string $rail): string
    {
        return array_search($rail, self::RAIL, true) ?: $rail;
    }

    public function mode(string $iso): string
    {
        try {
            $m = $this->corridors->country($iso)['routing_mode'] ?? null;
        } catch (\Throwable) {
            $m = null;
        }
        return isset(self::MODES[$m]) ? $m : (string) config('flashpay.failover.default_mode', 'auto');
    }

    /**
     * Rails à essayer pour ce flux, dans l'ordre (passerelle principale d'abord).
     * @param 'collect'|'payout' $flow
     * @return list<string>
     */
    public function chain(string $iso, string $flow): array
    {
        try {
            $c = $this->corridors->country($iso);
        } catch (\Throwable) {
            return [];
        }
        $primary = self::RAIL[$c[$flow . '_partner'] ?? 'peex'] ?? 'peex';
        $mode = $this->mode($iso);
        $list = [$primary];
        if ($mode === 'mixed') {
            foreach ((array) ($c[$flow . '_backups'] ?? []) as $p) {
                $list[] = self::RAIL[$p] ?? $p;
            }
        } elseif ($mode === 'auto') {
            $order = (array) config('flashpay.failover.order', []);
            $list = array_merge($list, $order, array_values(self::RAIL));
        }
        return array_values(array_unique(array_filter($list, fn ($r) => in_array($r, self::RAIL, true))));
    }

    /** Prochain rail de COLLECTE après un refus certain, ou null. */
    public function nextCollect(string $iso, array $tried, string $sandboxOf): ?string
    {
        if (! config('flashpay.failover.enabled', true) || ! config('flashpay.failover.collect', true)) {
            return null;
        }
        if (count($tried) > (int) config('flashpay.failover.max_switches', 2)) {
            return null;
        }
        $failover = app(PayoutFailover::class);
        $sandbox = $failover->sandbox($sandboxOf);
        foreach ($this->chain($iso, 'collect') as $rail) {
            if (in_array($rail, $tried, true)) {
                continue;
            }
            try {
                if ($failover->sandbox($rail) === $sandbox && $this->collectUsable($rail, $iso)) {
                    return $rail;
                }
            } catch (\Throwable $e) {
                Log::info("Bascule collecte : {$rail} écarté", ['country' => $iso, 'error' => $e->getMessage()]);
            }
        }
        return null;
    }

    public function collectUsable(string $rail, string $iso): bool
    {
        if (! config("flashpay.rails.{$rail}.enabled", false)) {
            return false;
        }
        return match ($rail) {
            'peex' => (bool) config('flashpay.peex.secret_key') && (bool) config("flashpay.corridors.{$iso}") && (bool) ($this->corridors->country($iso)['collect'] ?? false),
            'digitwace' => app(\App\Services\Digitwace\DigitwaceClient::class)->enabled()
                && ! empty(app(\App\Services\Digitwace\CoverageService::class)->byCountry()[$iso]['payin']),
            'pawapay_momo' => ($p = app(\App\Services\Remit\PawapayClient::class))->enabled() && ! empty($p->coverage()[$iso]['collect']),
            'maishapay' => ($m = app(\App\Services\Maishapay\MaishapayClient::class))->enabled() && in_array($iso, $m->countries(), true),
            'fincra' => ($f = app(\App\Services\Fincra\FincraClient::class))->enabled() && in_array($iso, $f->countries(), true),
            default => false,
        };
    }
}
