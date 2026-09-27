<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycDocument extends Model
{
    protected $table = 'kyc_documents';
    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
