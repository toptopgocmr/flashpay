<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillSplitShare extends Model
{
    protected $table = 'bill_split_shares';
    protected $guarded = ['id'];

    public function split() { return $this->belongsTo(BillSplit::class, 'bill_split_id'); }
    public function user() { return $this->belongsTo(User::class); }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
