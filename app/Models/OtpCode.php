<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $table = 'otp_codes';
    protected $guarded = ['id'];


    protected function casts(): array
    {
        return ['context' => 'array', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
