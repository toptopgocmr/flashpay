<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Note ajoutée par le Support/Opérations sur une transaction (cf. §2).
 */
class TransactionNote extends Model
{
    protected $fillable = ['transaction_id', 'author_id', 'note'];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
