<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'type', 'source_rail', 'destination_rail',
        'source_account', 'destination_account', 'source_wallet_id', 'destination_wallet_id',
        'source_external_ref', 'destination_external_ref',
        'amount', 'fee', 'currency', 'status', 'failure_reason', 'initiated_by', 'completed_at',
        'stage', 'meta', 'merchant_fee', 'destination_amount', 'destination_currency', 'scope',
    ];

    /**
     * Centre de notifications de la console : chaque paiement finalisé (entrant,
     * sortant, transfert, interne — réussi, échoué ou remboursé) y apparaît.
     */
    protected static function booted(): void
    {
        static::saved(function (Transaction $tx) {
            $final = in_array($tx->status, ['successful', 'failed', 'reversed'], true);
            if (! $final || (! $tx->wasRecentlyCreated && ! $tx->wasChanged('status'))) {
                return;
            }
            try {
                $tx->notifyAdmins();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Notification paiement non créée : ' . $e->getMessage());
            }
        });
    }

    /** entrant | sortant | transfert | interne, selon les rails de la transaction. */
    public function direction(): string
    {
        $in = $this->destination_rail === 'wallet';
        $out = $this->source_rail === 'wallet';
        return match (true) {
            $in && ! $out => 'in',
            $out && ! $in => 'out',
            $in && $out => 'internal',
            default => 'transfer',
        };
    }

    public function notifyAdmins(): void
    {
        $dir = $this->direction();
        $label = ['in' => 'Paiement entrant', 'out' => 'Paiement sortant', 'transfer' => 'Transfert', 'internal' => 'Paiement interne'][$dir];
        $state = ['successful' => 'réussi', 'failed' => 'échoué', 'reversed' => 'remboursé'][$this->status];
        $amount = number_format((int) $this->amount, 0, ',', ' ') . ' ' . ($this->currency ?: 'XAF');
        $who = trim(($this->source_account ?: $this->source_rail) . ' → ' . ($this->destination_account ?: $this->destination_rail));

        app(\App\Services\Notifications\NotificationService::class)->toAdmins(
            'payment_' . $dir,
            "{$label} {$state} · {$amount}",
            "{$who} · {$this->reference}" . ($this->failure_reason ? " — {$this->failure_reason}" : ''),
            [
                'severity' => $this->status === 'successful' ? 'success' : 'warning',
                'data' => ['transaction_id' => $this->id, 'reference' => $this->reference, 'direction' => $dir, 'status' => $this->status, 'amount' => (int) $this->amount],
            ],
        );
    }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'fee' => 'integer', 'merchant_fee' => 'integer', 'destination_amount' => 'integer', 'completed_at' => 'datetime', 'meta' => 'array'];
    }

    public function sourceWallet()
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id');
    }

    public function destinationWallet()
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function peexRequests()
    {
        return $this->hasMany(PeexRequest::class);
    }

    public function notes()
    {
        return $this->hasMany(TransactionNote::class);
    }
}
