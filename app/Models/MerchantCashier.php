<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantCashier extends Model
{
    protected $table = 'merchant_cashiers';
    protected $guarded = ['id'];

    public function merchant() { return $this->belongsTo(Merchant::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function outlet() { return $this->belongsTo(MerchantOutlet::class, 'outlet_id'); }

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
