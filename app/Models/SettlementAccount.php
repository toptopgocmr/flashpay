<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementAccount extends Model
{
    public const TYPES = [
        'mobile_money' => 'Mobile money',
        'bank' => 'Compte bancaire',
        'wallet' => 'Wallet FlashPay',
        'cash_pickup' => 'Retrait cash chez un agent',
    ];

    protected $fillable = [
        'merchant_id', 'type', 'label', 'country', 'phone', 'operator', 'bank_name',
        'account_holder', 'account_number', 'swift', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /** Libellé court pour les listes : « MTN · 06 555 12 34 », « BGFI · ****4521 ». */
    public function summary(): string
    {
        return match ($this->type) {
            'mobile_money' => trim(($this->operator ?: 'Mobile money') . ' · +' . ltrim((string) $this->phone, '+')),
            'wallet' => 'FlashPay · +' . ltrim((string) $this->phone, '+'),
            'bank' => trim(($this->bank_name ?: 'Banque') . ' · ****' . substr(preg_replace('/\s/', '', (string) $this->account_number), -4)),
            'cash_pickup' => 'Espèces chez un agent',
            default => $this->type,
        };
    }

    public function toApi(): array
    {
        return $this->only(['id', 'type', 'label', 'country', 'phone', 'operator', 'bank_name', 'account_holder', 'account_number', 'swift', 'is_default'])
            + ['type_label' => self::TYPES[$this->type] ?? $this->type, 'summary' => $this->summary()];
    }
}
