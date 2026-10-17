<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Zone de destination des transferts internationaux (CEMAC, Afrique de l'Ouest, Europe, Monde…). */
class RemitZone extends Model
{
    protected $fillable = ['name', 'description', 'threshold', 'countries', 'is_default', 'sort'];

    protected function casts(): array
    {
        return ['threshold' => 'integer', 'countries' => 'array', 'is_default' => 'boolean', 'sort' => 'integer'];
    }

    /** Zone d'un pays : celle qui le liste, sinon la zone par défaut (Monde). */
    public static function forCountry(string $iso): ?self
    {
        $iso = strtoupper($iso);
        try {
            $zones = static::orderBy('sort')->get();
        } catch (\Throwable) {
            return null;
        }
        return $zones->first(fn ($z) => in_array($iso, (array) $z->countries, true)) ?? $zones->firstWhere('is_default', true);
    }
}
