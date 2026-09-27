<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    protected $table = 'commission_rules';
    protected $guarded = ['id'];


    protected function casts(): array
    {
        return ['min_amount' => 'integer', 'max_amount' => 'integer', 'value' => 'float', 'active' => 'boolean'];
    }
}
