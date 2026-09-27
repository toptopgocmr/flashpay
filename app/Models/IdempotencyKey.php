<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';
    protected $guarded = ['id'];


    protected function casts(): array
    {
        return ['response_code' => 'integer'];
    }
}
