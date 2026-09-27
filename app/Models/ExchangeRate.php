<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Taux de change administrable : 1 {base} = {rate} {quote}.
 * La marge FlashPay (margin_percent) est retirée du montant converti.
 */
class ExchangeRate extends Model
{
    protected $fillable = ['base', 'quote', 'rate', 'margin_percent', 'active', 'source'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'margin_percent' => 'float', 'active' => 'boolean'];
    }
}
