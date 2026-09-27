<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentIntent extends Model
{
    protected $table = 'payment_intents';
    protected $guarded = ['id'];

    public function merchant() { return $this->belongsTo(Merchant::class); }
    public function apiKey() { return $this->belongsTo(MerchantApiKey::class, 'api_key_id'); }
    public function transaction() { return $this->belongsTo(Transaction::class); }
    public function refunds() { return $this->hasMany(Refund::class); }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'amount_refunded' => 'integer', 'metadata' => 'array', 'expires_at' => 'datetime', 'succeeded_at' => 'datetime'];
    }
}
