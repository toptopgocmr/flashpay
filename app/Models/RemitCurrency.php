<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Taux de change des transferts internationaux à partir du Congo : valeur en XAF d'une unité. */
class RemitCurrency extends Model
{
    protected $fillable = ['code', 'symbol', 'xaf_value', 'active'];

    protected function casts(): array
    {
        return ['xaf_value' => 'float', 'active' => 'boolean'];
    }

    public static function valueOf(string $code): ?float
    {
        try {
            $c = static::where('code', strtoupper($code))->where('active', true)->first();
        } catch (\Throwable) {
            return null;
        }
        return $c && $c->xaf_value > 0 ? (float) $c->xaf_value : null;
    }
}
