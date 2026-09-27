<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkedAccount extends Model
{
    protected $table = 'linked_accounts';
    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'verified_at' => 'datetime'];
    }
}
