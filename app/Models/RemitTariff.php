<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Palier de la grille des transferts internationaux (« * » = toutes les valeurs). */
class RemitTariff extends Model
{
    protected $fillable = ['zone_id', 'country', 'business_type', 'method', 'partner', 'min_amount', 'max_amount', 'fee_type', 'fee_value', 'min_fee', 'max_fee', 'active', 'label'];

    protected function casts(): array
    {
        return ['zone_id' => 'integer', 'min_amount' => 'integer', 'max_amount' => 'integer', 'fee_value' => 'float', 'min_fee' => 'integer', 'max_fee' => 'integer', 'active' => 'boolean'];
    }

    public function zone()
    {
        return $this->belongsTo(RemitZone::class);
    }

    /** Spécificité : le palier le plus précis l'emporte (pays > zone > type > réception > partenaire). */
    public function specificity(): int
    {
        return ($this->country !== '*' ? 16 : 0) + ($this->zone_id ? 8 : 0) + ($this->business_type !== '*' ? 4 : 0) + ($this->method !== '*' ? 2 : 0) + ($this->partner !== '*' ? 1 : 0);
    }

    public function feeFor(int $amount): int
    {
        $fee = $this->fee_type === 'fixed' ? (int) round($this->fee_value) : (int) ceil($amount * $this->fee_value / 100);
        $fee = max($fee, (int) $this->min_fee);
        if ($this->max_fee) {
            $fee = min($fee, (int) $this->max_fee);
        }
        return $fee;
    }
}
