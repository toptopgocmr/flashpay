<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $table = 'refunds';
    protected $guarded = ['id'];

    public function transaction() { return $this->belongsTo(Transaction::class); }
    public function merchant() { return $this->belongsTo(Merchant::class); }
    public function refundTransaction() { return $this->belongsTo(Transaction::class, 'refund_transaction_id'); }

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
