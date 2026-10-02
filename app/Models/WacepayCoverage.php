<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un payeur WacePay (opérateur mobile money, banque, cash) dans un pays, avec ses services. */
class WacepayCoverage extends Model
{
    protected $table = 'wacepay_coverage';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payin' => 'boolean', 'payout' => 'boolean', 'raw' => 'array', 'synced_at' => 'datetime'];
    }
}
