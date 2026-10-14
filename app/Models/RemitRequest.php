<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Transfert international envoyé à un partenaire de remittance (WacePay API v2 / TerraPay). */
class RemitRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_response' => 'array', 'last_checked_at' => 'datetime', 'finalized_at' => 'datetime'];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
