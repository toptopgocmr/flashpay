<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Profil KYC « international » de l'expéditeur (données d'identité chiffrées). */
class RemitProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'email_verified_at' => 'datetime',
            'id_expiry' => 'date',
            'gps_at' => 'datetime',
            'completed_at' => 'datetime',
            'gps_lat' => 'float',
            'gps_lng' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
