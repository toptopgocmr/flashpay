<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    protected $table = 'disputes';
    protected $guarded = ['id'];

    public function transaction() { return $this->belongsTo(Transaction::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }

    protected function casts(): array
    {
        return ['sla_due_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
