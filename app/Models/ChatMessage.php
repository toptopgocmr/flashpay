<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    /** Délai pour modifier un message envoyé (comme WhatsApp). */
    public const EDIT_MINUTES = 15;

    protected $guarded = ['id'];
    protected $hidden = ['content'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'edited_at' => 'datetime', 'forwarded' => 'boolean'];
    }

    public function conversation() { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function replyTo() { return $this->belongsTo(self::class, 'reply_to_id'); }

    public function fileResponse()
    {
        $bytes = base64_decode((string) $this->content, true);
        abort_if($bytes === false || $bytes === '', 404, 'Fichier introuvable.');
        $ext = match ($this->mime) {
            'audio/mp4', 'audio/x-m4a', 'audio/aac' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            default => explode('/', (string) $this->mime)[1] ?? 'bin',
        };

        return response($bytes, 200, [
            'Content-Type' => $this->mime ?: 'application/octet-stream',
            'Content-Length' => strlen($bytes),
            'Content-Disposition' => 'inline; filename="flashpay-' . $this->id . '.' . $ext . '"',
            'Cache-Control' => 'private, max-age=86400',
            'Accept-Ranges' => 'none',
        ]);
    }
}
