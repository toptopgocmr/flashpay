<?php

namespace App\Services\Payments;

use App\Models\Transaction;
use App\Services\Peex\PeexCorridors;
use Illuminate\Support\Facades\Log;

/**
 * Bascule automatique du VERSEMENT mobile money vers un autre partenaire.
 *
 * Utilisée seulement quand l'échec du partenaire est CERTAIN (refus explicite, ou
 * statut « échoué » relu auprès de son API) : rien n'a été versé, on peut donc
 * réessayer ailleurs sans risque de double paiement.
 *
 * Un partenaire de secours n'est retenu que s'il :
 *  - est activé et configuré (clés) ;
 *  - est dans le MÊME environnement que le partenaire en échec (jamais d'un compte
 *    de production vers une sandbox, ni l'inverse) ;
 *  - propose le versement dans ce pays (couverture) ;
 *  - a un solde suffisant quand il est connu ;
 *  - n'a pas déjà été essayé pour cette opération.
 */
class PayoutFailover
{
    public const RAILS = ['peex', 'digitwace', 'pawapay_momo', 'maishapay'];

    public const NAMES = ['peex' => 'PEEX', 'digitwace' => 'WacePay', 'pawapay_momo' => 'PawaPay', 'maishapay' => 'MaishaPay'];

    public function __construct(protected PeexCorridors $corridors)
    {
    }

    /** Prochain rail de versement à essayer, ou null. */
    public function next(Transaction $tx): ?string
    {
        if (! config('flashpay.failover.enabled', true) || ! in_array($tx->destination_rail, self::RAILS, true) || ! $tx->destination_account) {
            return null;
        }
        $meta = $tx->meta ?? [];
        $tried = array_merge([$tx->destination_rail], array_column((array) ($meta['payout_attempts'] ?? []), 'rail'));
        if (count($tried) > (int) config('flashpay.failover.max_switches', 2)) {
            return null;
        }
        $iso = strtoupper((string) ($meta['destination_country'] ?? ''));
        if ($iso === '') {
            try {
                $iso = $this->corridors->resolve($tx->destination_account)['country'];
            } catch (\Throwable) {
                return null;
            }
        }
        $sandbox = $this->sandbox($tx->destination_rail);
        $amount = (int) ($tx->destination_amount ?? $tx->amount);
        $currency = $tx->destination_currency ?: $tx->currency;

        foreach ((array) config('flashpay.failover.order', self::RAILS) as $rail) {
            if (in_array($rail, $tried, true) || ! in_array($rail, self::RAILS, true)) {
                continue;
            }
            try {
                if ($this->sandbox($rail) === $sandbox && $this->usable($rail, $iso, $amount, $currency)) {
                    return $rail;
                }
            } catch (\Throwable $e) {
                Log::info("Bascule versement : {$rail} écarté", ['reference' => $tx->reference, 'error' => $e->getMessage()]);
            }
        }
        return null;
    }

    public function sandbox(string $rail): bool
    {
        return match ($rail) {
            'peex' => (bool) config('flashpay.peex.sandbox', true),
            'digitwace' => (bool) config('flashpay.digitwace.sandbox', true),
            'pawapay_momo' => app(\App\Services\Remit\PawapayClient::class)->sandbox(),
            'maishapay' => app(\App\Services\Maishapay\MaishapayClient::class)->sandbox(),
            default => true,
        };
    }

    protected function usable(string $rail, string $iso, int $amount, string $currency): bool
    {
        if (! config("flashpay.rails.{$rail}.enabled", false)) {
            return false;
        }
        return match ($rail) {
            'peex' => (bool) config('flashpay.peex.secret_key') && (bool) ($this->corridors->country($iso)['payout'] ?? false),
            'digitwace' => app(\App\Services\Digitwace\DigitwaceClient::class)->enabled()
                && ! empty(app(\App\Services\Digitwace\CoverageService::class)->byCountry()[$iso]['payout'])
                && $this->enough(app(\App\Services\Digitwace\WacepayBalanceService::class)->availableFor($currency), $amount),
            'pawapay_momo' => ($p = app(\App\Services\Remit\PawapayClient::class))->enabled()
                && ! empty($p->coverage()[$iso]['payout'])
                && $this->enough($p->availableFor($currency, $iso), $amount),
            'maishapay' => ($m = app(\App\Services\Maishapay\MaishapayClient::class))->enabled() && in_array($iso, $m->countries(), true),
            default => false,
        };
    }

    protected function enough(?float $available, int $amount): bool
    {
        return $available === null || $available >= $amount;
    }
}
