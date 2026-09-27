<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    protected $table = 'user_devices';
    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'nfc_hce' => 'boolean'];
    }
}
