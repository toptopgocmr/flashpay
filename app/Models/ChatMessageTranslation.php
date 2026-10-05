<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessageTranslation extends Model
{
    protected $guarded = ['id'];

    public function message() { return $this->belongsTo(ChatMessage::class, 'message_id'); }
}
