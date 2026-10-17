<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Taxe appliquée aux transferts internationaux (COBAC, timbre, TTF, TVA…). */
class RemitTax extends Model
{
    protected $fillable = ['code', 'label', 'type', 'value', 'base', 'zone_id', 'active'];

    protected function casts(): array
    {
        return ['value' => 'float', 'active' => 'boolean'];
    }

    public function zone()
    {
        return $this->belongsTo(RemitZone::class);
    }

    /** Montant de la taxe (non arrondi) pour un montant envoyé et des frais hors taxes. */
    public function amountFor(int $amount, float $feeHt): float
    {
        if ($this->type === 'fixed') {
            return (float) $this->value;
        }
        $base = $this->base === 'fee' ? $feeHt : ($this->base === 'amount' ? $amount : 0);
        return $base * $this->value / 100;
    }
}
