<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'balance', 'currency', 'country', 'status'];

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    /**
     * Devises de wallet gérées (montants entiers, grille tarifaire et plafonds en XAF) :
     * un wallet dans une autre devise (EUR, USD…) est ouvert dans la devise de base
     * tant que ces devises ne sont pas prises en charge (FLASHPAY_WALLET_CURRENCIES).
     */
    protected static function booted(): void
    {
        static::creating(function (Wallet $w) {
            $allowed = (array) config('flashpay.wallet_currencies', ['XAF', 'XOF', 'CDF', 'GNF']);
            if (! $w->currency || ! in_array(strtoupper($w->currency), $allowed, true)) {
                $w->currency = config('flashpay.base_currency', 'XAF');
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function balanceFormatted(): string
    {
        return number_format($this->balance, 0, ',', ' ') . ' ' . $this->currency;
    }
}
