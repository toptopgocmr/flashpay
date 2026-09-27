<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayCode extends Model
{
    protected $fillable = ['user_id', 'code_hash', 'status', 'expires_at', 'used_at', 'transaction_id', 'used_by'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public static function hash(string $code): string
    {
        return hash('sha256', preg_replace('/\D/', '', $code));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
