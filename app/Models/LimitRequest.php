<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Demande de relèvement de plafonds par un utilisateur (avec justificatif). */
class LimitRequest extends Model
{
    public const REASONS = [
        'commerce' => 'Activité commerciale',
        'salaire' => 'Salaire / revenus réguliers',
        'evenement' => 'Événement (mariage, funérailles, voyage…)',
        'immobilier' => 'Loyer / achat important',
        'autre' => 'Autre',
    ];
    public const STATUSES = ['pending' => 'En cours d\'examen', 'approved' => 'Acceptée', 'rejected' => 'Refusée', 'cancelled' => 'Annulée'];

    protected $guarded = ['id'];
    protected $hidden = ['file_content'];

    protected function casts(): array
    {
        return ['current_limits' => 'array', 'granted' => 'array', 'granted_until' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function hasFile(): bool
    {
        return ! empty($this->getRawOriginal('file_content'));
    }

    public function fileResponse()
    {
        $bytes = base64_decode((string) $this->getRawOriginal('file_content'), true);
        abort_if($bytes === false || $bytes === '', 404, 'Justificatif introuvable.');
        return response($bytes, 200, [
            'Content-Type' => $this->file_mime ?: 'application/octet-stream',
            'Content-Length' => strlen($bytes),
            'Content-Disposition' => 'inline; filename="' . ($this->file_name ?: 'justificatif-' . $this->id) . '"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function present(): array
    {
        return $this->toArray() + [
            'reason_label' => self::REASONS[$this->reason_type] ?? $this->reason_type,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'has_file' => $this->hasFile(),
        ];
    }
}
