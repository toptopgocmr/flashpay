<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantApiKey extends Model
{
    protected $table = 'merchant_api_keys';
    protected $guarded = ['id'];

    protected $hidden = ['secret_hash', 'webhook_secret'];
    public function merchant() { return $this->belongsTo(Merchant::class); }

    protected function casts(): array
    {
        return ['allowed_ips' => 'array', 'active' => 'boolean', 'last_used_at' => 'datetime', 'webhook_secret' => 'encrypted'];
    }
}
