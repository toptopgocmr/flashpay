<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bénéficiaire international enregistré par le client (données chiffrées). */
class RemitBeneficiary extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['data', 'fingerprint'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'favorite' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Fiche renvoyée à son propriétaire (pré-remplissage du formulaire d'envoi). */
    public function toOwnerArray(): array
    {
        $d = (array) ($this->data ?? []);
        return [
            'id' => $this->id,
            'label' => $this->label,
            'country' => $this->country,
            'method' => $this->method,
            'business_type' => $this->business_type,
            'service_name' => $this->service_name,
            'account_hint' => $this->account_hint,
            'favorite' => (bool) $this->favorite,
            'uses' => (int) $this->uses,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'service' => $d['service'] ?? null,
            'beneficiary' => $d['beneficiary'] ?? [],
            'account' => $d['account'] ?? [],
            'relation' => $d['relation'] ?? null,
        ];
    }
}
