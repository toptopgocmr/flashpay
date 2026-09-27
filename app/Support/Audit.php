<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Trace immuable d'une action sensible (intervention admin, KYC, remboursement…). */
class Audit
{
    public static function log(string $action, ?Model $subject = null, array $data = [], ?int $actorId = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'actor_id' => $actorId ?? $request?->user()?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data ?: null,
            'ip' => $request?->ip(),
        ]);
    }
}
