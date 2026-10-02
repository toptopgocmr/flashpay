<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pays ajouté au catalogue depuis la console / la synchro WacePay (hors config/corridors.php). */
class CorridorCountry extends Model
{
    protected $primaryKey = 'country';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['operators' => 'array', 'local_length' => 'integer'];
    }
}
