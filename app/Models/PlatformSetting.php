<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $table = 'platform_settings';
    protected $guarded = ['id'];

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
