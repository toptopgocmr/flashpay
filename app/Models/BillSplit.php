<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillSplit extends Model
{
    protected $table = 'bill_splits';
    protected $guarded = ['id'];

    public function creator() { return $this->belongsTo(User::class, 'creator_id'); }
    public function shares() { return $this->hasMany(BillSplitShare::class); }

    protected function casts(): array
    {
        return ['total_amount' => 'integer'];
    }
}
