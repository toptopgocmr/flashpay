<?php

namespace App\Services\Remit;

use App\Models\RemitTariff;
use App\Services\Payments\FeeService;

/**
 * Frais FlashPay d'un transfert international, d'après la grille dédiée (Console › Tarifs
 * internationaux). Palier retenu : actif, dans la tranche, et le plus précis (pays, puis type,
 * réception, partenaire). Sans palier : ancienne grille (p2p international).
 */
class RemitFeeService
{
    public function __construct(protected FeeService $fees)
    {
    }

    /** @return array{fee:int, tariff:?RemitTariff} */
    public function compute(string $country, string $businessType, string $method, ?string $partner, int $amount): array
    {
        $t = $this->match(strtoupper($country), $businessType, $method, (string) $partner, $amount);
        if ($t) {
            return ['fee' => $t->feeFor($amount), 'tariff' => $t];
        }
        return ['fee' => $this->fees->fee('p2p', 'international', $amount), 'tariff' => null];
    }

    public function match(string $country, string $businessType, string $method, string $partner, int $amount): ?RemitTariff
    {
        try {
            return RemitTariff::where('active', true)
                ->where('min_amount', '<=', $amount)->where('max_amount', '>=', $amount)
                ->whereIn('country', [$country, '*'])->whereIn('business_type', [$businessType, '*'])
                ->whereIn('method', [$method, '*'])->whereIn('partner', [$partner ?: '*', '*'])
                ->get()
                ->sortByDesc(fn (RemitTariff $t) => [$t->specificity(), $t->min_amount])
                ->first();
        } catch (\Throwable) {
            return null; // table absente (migration non lancée)
        }
    }
}
