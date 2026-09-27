<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'float_balance', 'zone', 'country', 'city', 'validation_status', 'validated_at',
        'agent_code', 'pos_code', 'is_super_agent', 'parent_agent_id', 'low_float_threshold', 'low_float_alerted_at'];

    protected function casts(): array
    {
        return ['float_balance' => 'integer', 'validated_at' => 'datetime', 'is_super_agent' => 'boolean', 'low_float_threshold' => 'integer', 'low_float_alerted_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(Agent::class, 'parent_agent_id');
    }

    public function subAgents()
    {
        return $this->hasMany(Agent::class, 'parent_agent_id');
    }

    public function floatRequests()
    {
        return $this->hasMany(FloatRequest::class);
    }

    protected static function booted(): void
    {
        // Identifiant agent unique + code point de vente (§3.1.1)
        static::created(function (Agent $a) {
            if (! $a->agent_code) {
                $a->updateQuietly([
                    'agent_code' => 'AG' . str_pad((string) $a->id, 6, '0', STR_PAD_LEFT),
                    'pos_code' => $a->pos_code ?: 'PV' . strtoupper(\Illuminate\Support\Str::random(6)),
                ]);
            }
        });
    }
}
