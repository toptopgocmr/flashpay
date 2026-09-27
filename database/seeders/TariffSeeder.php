<?php

namespace Database\Seeders;

use App\Models\Tariff;
use Illuminate\Database\Seeder;

/**
 * Grille "style Wave" par défaut — modifiable dans Console > Grille tarifaire.
 *   Dépôt / retrait                : gratuits
 *   Envoi national / régional / international : 1 % / 2 % / 3 %
 *   Paiement marchand              : gratuit pour le client, 1 % à la charge du marchand
 */
class TariffSeeder extends Seeder
{
    public function run(): void
    {
        // Anciennes lignes par défaut (avant la gestion des zones)
        Tariff::where('max_amount', 5000000)->where('min_amount', 0)->where('scope', 'national')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('operation_type', 'p2p')->where('fee_value', 1.0))
                ->orWhere(fn ($q) => $q->where('operation_type', 'merchant_payment')->where('fee_value', 0.5))
                ->orWhere(fn ($q) => $q->where('operation_type', 'withdrawal')->where('fee_value', 1.5)))
            ->delete();

        $max = 1_000_000_000;
        $grid = [
            'p2p' => ['national' => 1.0, 'regional' => 2.0, 'international' => 3.0],
            'merchant_payment' => ['national' => 0, 'regional' => 0, 'international' => 0],
            'merchant_fee' => ['national' => 1.0, 'regional' => 1.0, 'international' => 1.0],
            'cash_in' => ['national' => 0, 'regional' => 0, 'international' => 0],
            'withdrawal' => ['national' => 0, 'regional' => 0, 'international' => 0],
            'cash_out' => ['national' => 0],
            // Bons de retrait : cash pickup chez un agent, GAB partenaire (50 % des frais reversés à l'agent)
            'cash_pickup' => ['national' => 1.0, 'regional' => 1.5],
            'atm' => ['national' => 1.0, 'regional' => 1.5],
            // Surcoût carte Visa / Mastercard (coût de la passerelle carte), ajouté aux frais de l'opération
            'card' => ['national' => 2.5],
            // Règlement marchand par virement bancaire
            'bank_transfer' => ['national' => 0.5],
        ];

        foreach ($grid as $operation => $scopes) {
            foreach ($scopes as $scope => $percent) {
                Tariff::firstOrCreate(
                    ['operation_type' => $operation, 'scope' => $scope, 'min_amount' => 0, 'max_amount' => $max],
                    ['fee_type' => 'percent', 'fee_value' => $percent, 'min_fee' => 0, 'active' => true],
                );
            }
        }
    }
}
