<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';
    protected $guarded = ['id'];

    public function merchant() { return $this->belongsTo(Merchant::class); }
    public function apiKey() { return $this->belongsTo(MerchantApiKey::class, 'api_key_id'); }

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
