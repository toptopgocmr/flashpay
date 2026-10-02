<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Demande de versement (ou d'encaissement) envoyée à Digitwace / WacePay. */
class DigitwaceRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_response' => 'array',
            'last_callback' => 'array',
            'last_checked_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['successful', 'failed'], true);
    }
}
