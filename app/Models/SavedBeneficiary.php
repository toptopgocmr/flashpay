<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bénéficiaire enregistré automatiquement après un envoi (carnet du client). */
class SavedBeneficiary extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['favorite' => 'boolean', 'last_used_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
