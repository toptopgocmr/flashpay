<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Demande d'argent d'un utilisateur à un autre (payée en wallet → wallet). */
class MoneyRequest extends Model
{
    protected $guarded = ['id'];

    public function requester() { return $this->belongsTo(User::class, 'requester_id'); }
    public function payer() { return $this->belongsTo(User::class, 'payer_id'); }
    public function transaction() { return $this->belongsTo(Transaction::class); }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && (! $this->expires_at || $this->expires_at->isFuture());
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'expires_at' => 'datetime', 'answered_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
