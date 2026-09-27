<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftClaim extends Model
{
    protected $table = 'gift_claims';
    protected $guarded = ['id'];

    public function envelope() { return $this->belongsTo(GiftEnvelope::class, 'gift_envelope_id'); }
    public function recipient() { return $this->belongsTo(User::class, 'recipient_id'); }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'claimed_at' => 'datetime'];
    }
}
