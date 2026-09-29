<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeexRequest extends Model
{
    /** « unknown » : appel interrompu, issue à vérifier auprès de PEEX (jamais traité comme un échec). */
    public const PENDING_STATUSES = ['new', 'pending', 'unknown'];
    public const SUCCESS_STATUSES = ['paid'];
    public const FAILED_STATUSES = ['failed', 'rejected', 'canceled', 'cancelled', 'error'];

    protected $fillable = [
        'transaction_id', 'service', 'track_id', 'peex_id', 'country', 'corridor', 'phone',
        'amount', 'currency', 'fees', 'status', 'payment_proof', 'message', 'sandbox',
        'request_payload', 'response_payload', 'last_callback', 'last_checked_at', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'sandbox' => 'boolean',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'last_callback' => 'array',
            'last_checked_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [...self::SUCCESS_STATUSES, ...self::FAILED_STATUSES], true);
    }

    /** Statut normalisé FlashPay : successful | pending | failed */
    public static function normalize(?string $peexStatus): string
    {
        $s = strtolower((string) $peexStatus);
        if (in_array($s, self::SUCCESS_STATUSES, true)) {
            return 'successful';
        }
        if (in_array($s, self::FAILED_STATUSES, true)) {
            return 'failed';
        }
        return 'pending';
    }
}
