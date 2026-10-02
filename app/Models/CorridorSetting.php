<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Surcharge console d'un corridor (null = valeur par défaut de config/corridors.php). */
class CorridorSetting extends Model
{
    protected $primaryKey = 'country';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['country', 'collect', 'payout', 'payout_api', 'collect_partner', 'payout_partner', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['collect' => 'boolean', 'payout' => 'boolean'];
    }
}
