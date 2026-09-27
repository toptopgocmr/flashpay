<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

/**
 * Taux INDICATIFS de démarrage — à mettre à jour dans Console > Taux de change.
 * XAF ↔ XOF n'a pas besoin de taux (parité fixe 1:1).
 */
class ExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            ['XAF', 'CDF', 4.80, 1.5],
            ['XAF', 'GNF', 14.50, 1.5],
            ['XAF', 'USD', 0.00175, 1.5],
            ['XAF', 'EUR', 1 / 655.957, 0],
        ];

        foreach ($rates as [$base, $quote, $rate, $margin]) {
            ExchangeRate::firstOrCreate(
                ['base' => $base, 'quote' => $quote],
                ['rate' => $rate, 'margin_percent' => $margin, 'active' => true, 'source' => 'indicatif — à mettre à jour'],
            );
        }
    }
}
