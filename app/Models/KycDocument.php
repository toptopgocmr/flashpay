<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class KycDocument extends Model
{
    protected $table = 'kyc_documents';
    protected $guarded = ['id'];

    /** Le contenu (base64) n'est jamais renvoyé dans les listes JSON. */
    protected $hidden = ['content'];

    public function user() { return $this->belongsTo(User::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function hasFile(): bool
    {
        return ($this->path && Storage::disk('local')->exists($this->path)) || ! empty($this->getRawOriginal('content'));
    }

    /** Réponse HTTP avec le fichier : disque si présent, sinon copie en base. */
    public function fileResponse()
    {
        if ($this->path && Storage::disk('local')->exists($this->path)) {
            return Storage::disk('local')->response($this->path, null, ['Cache-Control' => 'private, max-age=300']);
        }
        $raw = $this->getRawOriginal('content');
        abort_if(empty($raw), 404, 'Fichier introuvable. Merci de renvoyer la pièce.');
        $bytes = base64_decode($raw);
        $mime = $this->mime ?: ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream');

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => strlen($bytes),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
