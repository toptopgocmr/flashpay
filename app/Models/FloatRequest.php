<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloatRequest extends Model
{
    protected $table = 'float_requests';
    protected $guarded = ['id'];

    public function agent() { return $this->belongsTo(Agent::class); }
    public function superAgent() { return $this->belongsTo(Agent::class, 'super_agent_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function transaction() { return $this->belongsTo(Transaction::class); }

    protected function casts(): array
    {
        return ['amount' => 'integer', 'reviewed_at' => 'datetime'];
    }
}
