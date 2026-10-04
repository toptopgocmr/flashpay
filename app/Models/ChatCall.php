<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Appel audio entre deux utilisateurs FlashPay (signalisation WebRTC). */
class ChatCall extends Model
{
    /** Au-delà, un appel qui sonne toujours est considéré comme manqué. */
    public const RING_SECONDS = 45;

    protected $guarded = ['id'];
    protected $hidden = ['offer', 'answer'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function conversation() { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function caller() { return $this->belongsTo(User::class, 'caller_id'); }
    public function callee() { return $this->belongsTo(User::class, 'callee_id'); }

    public function isParticipant(User $u): bool
    {
        return in_array($u->id, [(int) $this->caller_id, (int) $this->callee_id], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['ringing', 'accepted'], true);
    }

    /** Durée de la conversation (secondes), 0 si l'appel n'a pas été décroché. */
    public function talkSeconds(): int
    {
        if (! $this->answered_at) {
            return 0;
        }
        return max(0, (int) $this->answered_at->diffInSeconds($this->ended_at ?? now(), true));
    }
}
