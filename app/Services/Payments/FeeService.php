<?php

namespace App\Services\Payments;

use App\Models\Tariff;

/**
 * Calcul des frais d'après la grille tarifaire (opération × zone × tranche).
 * Zones : national | regional | international. Si aucune ligne n'existe pour
 * la zone demandée, on retombe sur la zone "national".
 */
class FeeService
{
    public function fee(string $operation, string $scope, int $amount): int
    {
        $tariff = $this->tariffFor($operation, $scope, $amount)
            ?? ($scope !== 'national' ? $this->tariffFor($operation, 'national', $amount) : null);

        if (! $tariff) {
            return 0;
        }

        $fee = $tariff->fee_type === 'fixed'
            ? (int) round($tariff->fee_value)
            : (int) ceil($amount * $tariff->fee_value / 100);

        $fee = max($fee, (int) $tariff->min_fee);
        if ($tariff->max_fee) {
            $fee = min($fee, (int) $tariff->max_fee);
        }

        return $fee;
    }

    protected function tariffFor(string $operation, string $scope, int $amount): ?Tariff
    {
        return Tariff::where('operation_type', $operation)
            ->where('scope', $scope)
            ->where('active', true)
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->orderByDesc('min_amount')
            ->first();
    }
}
