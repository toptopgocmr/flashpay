<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agence, point de vente ou GAB affiché sur la carte « Localiser un agent ». */
class ServicePoint extends Model
{
    public const TYPES = ['agency' => 'Agence OfficePay', 'point_of_sale' => 'Point de vente', 'atm' => 'GAB'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'services' => 'array', 'active' => 'boolean'];
    }
}
