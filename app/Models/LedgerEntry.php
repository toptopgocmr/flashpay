<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = ['transaction_id', 'account', 'type', 'amount', 'currency', 'balance_after', 'memo'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
