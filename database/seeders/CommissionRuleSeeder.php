<?php

namespace Database\Seeders;

use App\Models\CommissionRule;
use Illuminate\Database\Seeder;

/** Barème de commissions agents par défaut (§3.1.7) — indicatif, modifiable dans la console. */
class CommissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        if (CommissionRule::exists()) {
            return;
        }
        $rules = [
            ['operation' => 'cash_in', 'min_amount' => 0, 'max_amount' => 50000, 'type' => 'fixed', 'value' => 100],
            ['operation' => 'cash_in', 'min_amount' => 50001, 'max_amount' => null, 'type' => 'percent', 'value' => 0.3],
            ['operation' => 'cash_out', 'min_amount' => 0, 'max_amount' => null, 'type' => 'fee_share', 'value' => 50],
            ['operation' => 'external_transfer', 'min_amount' => 0, 'max_amount' => null, 'type' => 'fee_share', 'value' => 30],
            ['operation' => 'float_request', 'min_amount' => 0, 'max_amount' => null, 'type' => 'percent', 'value' => 0.1],
        ];
        foreach ($rules as $r) {
            CommissionRule::create($r + ['active' => true]);
        }
    }
}
