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
            // Recharge « cadeau » payée par mobile money / carte : envoi du cadeau.
            if ($tx->status === 'successful' && ! empty(($tx->meta ?? [])['pending_gift'])) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Client\GiftService::class)->completeFunding($tx->fresh()));
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

    /** Libellés lisibles (reçu, application). */
    public const TYPE_LABELS = [
        'p2p' => "Envoi d'argent", 'transfer' => "Envoi d'argent", 'merchant_payment' => 'Paiement marchand',
        'cash_in' => 'Recharge', 'deposit' => 'Recharge', 'withdrawal' => 'Retrait', 'cash_out' => 'Retrait chez un agent',
        'cash_pickup' => 'Retrait avec code', 'bank_transfer' => 'Virement bancaire', 'gift' => 'Cadeau envoyé',
        'gift_claim' => 'Cadeau reçu', 'gift_refund' => 'Cadeau remboursé', 'refund' => 'Remboursement',
        'split_payment' => 'Part de note partagée', 'float_topup' => 'Approvisionnement agent', 'adjustment' => 'Ajustement',
    ];

    public const STATUS_LABELS = [
        'successful' => 'Réussie', 'failed' => 'Échouée', 'reversed' => 'Remboursée', 'processing' => 'En cours', 'pending' => 'En cours',
    ];

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    /** L'utilisateur est-il partie prenante (initiateur, payeur ou bénéficiaire) ? */
    public function concerns(User $u): bool
    {
        if ((int) $this->initiated_by === (int) $u->id) {
            return true;
        }
        $walletIds = Wallet::where('user_id', $u->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        return in_array((int) $this->source_wallet_id, $walletIds, true) || in_array((int) $this->destination_wallet_id, $walletIds, true);
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
