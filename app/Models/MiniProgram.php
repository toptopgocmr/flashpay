<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MiniProgram extends Model
{
    protected $table = 'mini_programs';
    protected $guarded = ['id'];

    public function merchant() { return $this->belongsTo(Merchant::class); }

    protected function casts(): array
    {
        return [];
    }
}
