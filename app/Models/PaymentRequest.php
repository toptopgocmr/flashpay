<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    protected $table = 'payment_requests';
    protected $guarded = ['id'];

    public function merchant() { return $this->belongsTo(Merchant::class); }
    public function outlet() { return $this->belongsTo(MerchantOutlet::class, 'outlet_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function transaction() { return $this->belongsTo(Transaction::class); }

    public function isPayable(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];
    }
}
