<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'business_name', 'business_category', 'country', 'city', 'address', 'settlement_phone', 'qr_code_token',
        'validation_status', 'validated_at', 'validated_by',
        'auto_settlement', 'auto_settlement_min', 'last_auto_settlement_at', 'online_payments', 'integration_validated_at', 'integration_validated_by', 'integration_live_at',
    ];

    protected function casts(): array
    {
        return ['validated_at' => 'datetime', 'last_auto_settlement_at' => 'datetime', 'auto_settlement_min' => 'integer', 'online_payments' => 'boolean', 'integration_validated_at' => 'datetime', 'integration_live_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlets()
    {
        return $this->hasMany(MerchantOutlet::class);
    }

    public function settlementAccounts()
    {
        return $this->hasMany(SettlementAccount::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function cashiers()
    {
        return $this->hasMany(MerchantCashier::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(MerchantApiKey::class);
    }
}
