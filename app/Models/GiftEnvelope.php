<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftEnvelope extends Model
{
    protected $table = 'gift_envelopes';
    protected $guarded = ['id'];

    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function claims() { return $this->hasMany(GiftClaim::class); }
    public function wallet() { return $this->belongsTo(Wallet::class); }

    protected function casts(): array
    {
        return ['total_amount' => 'integer', 'remaining_amount' => 'integer', 'expires_at' => 'datetime', 'reminder_sent' => 'boolean'];
    }
}
