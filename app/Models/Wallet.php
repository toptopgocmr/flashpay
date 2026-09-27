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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function balanceFormatted(): string
    {
        return number_format($this->balance, 0, ',', ' ') . ' ' . $this->currency;
    }
}
