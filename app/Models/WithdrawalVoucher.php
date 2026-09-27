<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawalVoucher extends Model
{
    protected $fillable = [
        'transaction_id', 'user_id', 'wallet_id', 'channel', 'country', 'code_hash', 'code_encrypted',
        'amount', 'fee', 'currency', 'beneficiary_name', 'beneficiary_phone', 'status', 'expires_at',
        'redeemed_at', 'redeemed_by', 'partner_ref', 'failed_attempts',
    ];

    protected $hidden = ['code_hash', 'code_encrypted'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', 'fee' => 'integer', 'failed_attempts' => 'integer',
            'code_encrypted' => 'encrypted', 'expires_at' => 'datetime', 'redeemed_at' => 'datetime',
        ];
    }

    public static function hash(string $code): string
    {
        return hash('sha256', preg_replace('/\D/', '', $code));
    }

    public function isRedeemable(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
