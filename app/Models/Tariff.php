<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Grille tarifaire configurable par le Super Admin (cf. §2).
 */
class Tariff extends Model
{
    public const OPERATIONS = [
        'p2p' => 'Envoi d\'argent',
        'merchant_payment' => 'Paiement marchand (client)',
        'merchant_fee' => 'Commission marchand',
        'cash_in' => 'Dépôt / recharge',
        'withdrawal' => 'Retrait vers mobile money',
        'cash_out' => 'Retrait chez un agent',
        'cash_pickup' => 'Retrait cash avec code (agent)',
        'atm' => 'Retrait au GAB',
        'card' => 'Frais carte Visa / Mastercard',
        'bank_transfer' => 'Virement bancaire (règlement marchand)',
    ];

    public const SCOPES = ['national', 'regional', 'international'];

    protected $fillable = ['operation_type', 'scope', 'min_amount', 'max_amount', 'fee_type', 'fee_value', 'min_fee', 'max_fee', 'active'];

    protected function casts(): array
    {
        return ['min_amount' => 'integer', 'max_amount' => 'integer', 'fee_value' => 'float', 'min_fee' => 'integer', 'max_fee' => 'integer', 'active' => 'boolean'];
    }
}
