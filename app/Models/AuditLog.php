<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'audit immuable (§4.5, §3.4.3, §11.4) : aucune mise à jour ni
 * suppression possible depuis l'application ; chaque ligne est chaînée à la
 * précédente (hash sha256) pour détecter toute altération en base.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $log->created_at ??= now();
            $prev = static::query()->latest('id')->value('hash') ?? '';
            $log->hash = hash('sha256', $prev . '|' . $log->action . '|' . $log->subject_type . '|' . $log->subject_id . '|' . json_encode($log->data) . '|' . $log->actor_id . '|' . $log->created_at->toIso8601String());
        });
        static::updating(fn () => throw new \LogicException('Le journal d\'audit est immuable.'));
        static::deleting(fn () => throw new \LogicException('Le journal d\'audit est immuable.'));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
