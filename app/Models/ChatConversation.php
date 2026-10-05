<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatConversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function userOne() { return $this->belongsTo(User::class, 'user_one_id'); }
    public function userTwo() { return $this->belongsTo(User::class, 'user_two_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function calls() { return $this->hasMany(ChatCall::class, 'conversation_id'); }
    public function messages() { return $this->hasMany(ChatMessage::class, 'conversation_id'); }

    public function hasParticipant(User $u): bool
    {
        return in_array($u->id, [(int) $this->user_one_id, (int) $this->user_two_id], true);
    }

    public function otherId(User $u): int
    {
        return (int) ($this->user_one_id == $u->id ? $this->user_two_id : $this->user_one_id);
    }

    public static function between(User $a, User $b): self
    {
        [$one, $two] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];
        return static::firstOrCreate(['user_one_id' => $one, 'user_two_id' => $two]);
    }
}
