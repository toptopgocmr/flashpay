<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Collecte (direct charge) ou versement (transfer) envoyé à Notch Pay. */
class NotchPayRequest extends Model
{
    protected $table = 'notchpay_requests';

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
