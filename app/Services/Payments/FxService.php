<?php

namespace App\Services\Payments;

use App\Models\ExchangeRate;

/**
 * Conversion de devises (XAF, XOF, CDF, GNF, USD…).
 *  - XAF ↔ XOF : parité fixe 1:1 (même ancrage à l'euro), sans marge.
 *  - autres    : table exchange_rates (1 base = rate quote), marge FlashPay déduite.
 */
class FxService
{
    /**
     * @return array{amount:int,rate:float,margin_percent:float,from:string,to:string,source:string}
     */
    public function convert(int $amount, string $from, string $to): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return ['amount' => $amount, 'rate' => 1.0, 'margin_percent' => 0.0, 'from' => $from, 'to' => $to, 'source' => 'identique'];
        }

        $parity = config('flashpay.fixed_parity', ['XAF', 'XOF']);
        if (in_array($from, $parity, true) && in_array($to, $parity, true)) {
            return ['amount' => $amount, 'rate' => 1.0, 'margin_percent' => 0.0, 'from' => $from, 'to' => $to, 'source' => 'parité fixe'];
        }

        [$rate, $margin, $source] = $this->rate($from, $to);
        $converted = (int) floor($amount * $rate * (1 - $margin / 100));

        return ['amount' => $converted, 'rate' => round($rate * (1 - $margin / 100), 8), 'margin_percent' => $margin, 'from' => $from, 'to' => $to, 'source' => $source];
    }

    /** @return array{0:float,1:float,2:string} [taux brut, marge %, origine] */
    public function rate(string $from, string $to): array
    {
        $direct = ExchangeRate::where('active', true)->where('base', $from)->where('quote', $to)->first();
        if ($direct) {
            return [$direct->rate, $direct->margin_percent, $direct->source ?? 'manuel'];
        }

        $inverse = ExchangeRate::where('active', true)->where('base', $to)->where('quote', $from)->first();
        if ($inverse && $inverse->rate > 0) {
            return [1 / $inverse->rate, $inverse->margin_percent, ($inverse->source ?? 'manuel') . ' (inverse)'];
        }

        // Triangulation via la devise de référence (XAF) — XOF assimilé à XAF
        $pivot = config('flashpay.base_currency', 'XAF');
        $norm = fn (string $c) => in_array($c, config('flashpay.fixed_parity', []), true) ? $pivot : $c;
        if ($norm($from) !== $from || $norm($to) !== $to) {
            if ($norm($from) === $norm($to)) {
                return [1.0, 0.0, 'parité fixe'];
            }
            return $this->rate($norm($from), $norm($to));
        }
        if ($from !== $pivot && $to !== $pivot) {
            [$a, $ma] = $this->rate($from, $pivot);
            [$b, $mb] = $this->rate($pivot, $to);
            return [$a * $b, max($ma, $mb), 'triangulation ' . $pivot];
        }

        throw new \RuntimeException("Aucun taux de change actif pour {$from} → {$to}. Configurez-le dans Console > Taux de change.");
    }
}
