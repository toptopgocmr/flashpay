<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    protected $table = 'support_messages';
    protected $guarded = ['id'];

    public function author() { return $this->belongsTo(User::class, 'author_id'); }

    protected function casts(): array
    {
        return [];
    }
}
