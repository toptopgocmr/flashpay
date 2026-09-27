<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FraudAlert extends Model
{
    protected $table = 'fraud_alerts';
    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }
    public function transaction() { return $this->belongsTo(Transaction::class); }

    protected function casts(): array
    {
        return ['details' => 'array', 'reviewed_at' => 'datetime'];
    }
}
