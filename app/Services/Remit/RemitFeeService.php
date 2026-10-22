<?php

namespace App\Services\Remit;

use App\Models\RemitCurrency;
use App\Models\RemitTariff;
use App\Models\RemitTax;
use App\Models\RemitZone;
use App\Services\Payments\FeeService;
use App\Services\Payments\FxService;

/**
 * Tarification d'un transfert international selon les normes (Console › Tarifs internationaux) :
 *
 *  1. zone du pays (CEMAC, Afrique de l'Ouest, Europe, Monde…) et son seuil (montant max par transfert) ;
 *  2. frais hors taxes : palier actif de la tranche, le plus précis (pays > zone > type > réception > partenaire) ;
 *  3. taxes actives de la zone ou « toutes zones » : % du montant (COBAC, TTF), % des frais (TVA), fixe (timbre) ;
 *     frais TTC = HT + taxes (arrondi au franc) — c'est ce que paie le client en plus du montant ;
 *  4. change « à partir du Congo » : montant reçu = montant XAF ÷ valeur XAF d'une unité de la devise.
 */
class RemitFeeService
{
    public function __construct(protected FeeService $fees)
    {
    }

    /**
     * @return array{fee:int, fee_ht:int, taxes_total:int, taxes:array, tariff:?RemitTariff, zone:?RemitZone, threshold:?int}
     */
    public function compute(string $country, string $businessType, string $method, ?string $partner, int $amount): array
    {
        $country = strtoupper($country);
        $zone = RemitZone::forCountry($country);
        $t = $this->match($country, $businessType, $method, (string) $partner, $amount, $zone);
        $ht = $t ? $t->feeFor($amount) : $this->fees->fee('p2p', 'international', $amount);
        [$taxes, $sum] = $this->taxes($amount, $ht, $zone);
        $ttc = (int) round($ht + $sum);

        return [
            'fee' => $ttc,
            'fee_ht' => $ht,
            'taxes_total' => $ttc - $ht,
            'taxes' => $taxes,
            'tariff' => $t,
            'zone' => $zone,
            'threshold' => $zone?->threshold ?: null,
        ];
    }

    /** @return array{0:array<int,array{code:string,label:string,type:string,value:float,base:string,amount:float}>,1:float} */
    public function taxes(int $amount, float $feeHt, ?RemitZone $zone): array
    {
        try {
            $list = RemitTax::where('active', true)
                ->where(fn ($q) => $q->whereNull('zone_id')->when($zone, fn ($q) => $q->orWhere('zone_id', $zone->id)))
                ->orderBy('id')->get();
        } catch (\Throwable) {
            return [[], 0.0];
        }
        $out = [];
        $sum = 0.0;
        foreach ($list as $tax) {
            $a = $tax->amountFor($amount, $feeHt);
            $sum += $a;
            $out[] = ['code' => $tax->code, 'label' => $tax->label, 'type' => $tax->type, 'value' => $tax->value, 'base' => $tax->base, 'amount' => round($a, 2)];
        }
        return [$out, $sum];
    }

    public function match(string $country, string $businessType, string $method, string $partner, int $amount, ?RemitZone $zone = null): ?RemitTariff
    {
        $zone ??= RemitZone::forCountry($country);
        try {
            return RemitTariff::where('active', true)
                ->where('min_amount', '<=', $amount)->where('max_amount', '>=', $amount)
                ->whereIn('country', [$country, '*'])->whereIn('business_type', [$businessType, '*'])
                ->whereIn('method', [$method, '*'])->whereIn('partner', [$partner ?: '*', '*'])
                ->where(fn ($q) => $q->whereNull('zone_id')->when($zone, fn ($q) => $q->orWhere('zone_id', $zone->id)))
                ->get()
                ->sortByDesc(fn (RemitTariff $t) => [$t->specificity(), $t->min_amount])
                ->first();
        } catch (\Throwable) {
            return null; // table absente (migration non lancée)
        }
    }

    /**
     * Change « à partir du Congo » (Console › Tarifs internationaux › Taux de change) :
     * montant reçu = montant en XAF ÷ valeur XAF d'une unité. Devise absente de la table → FxService.
     */
    public function convert(int $amount, string $from, string $to): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        $parity = (array) config('flashpay.fixed_parity', ['XAF', 'XOF']);
        $vFrom = in_array($from, $parity, true) ? 1.0 : RemitCurrency::valueOf($from);
        $vTo = in_array($to, $parity, true) ? 1.0 : RemitCurrency::valueOf($to);
        if ($from === $to || ! $vFrom || ! $vTo) {
            return app(FxService::class)->convert($amount, $from, $to);
        }
        $rate = $vFrom / $vTo;

        return [
            'amount' => (int) floor($amount * $rate),
            'rate' => round($rate, 8),
            'xaf_value' => $vTo,            // 1 unité de la devise reçue = x XAF
            'margin_percent' => 0.0,        // marge incluse dans la valeur publiée
            'from' => $from, 'to' => $to,
            'source' => 'taux de change OfficePay (à partir du Congo)',
        ];
    }
}
