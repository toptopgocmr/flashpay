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
