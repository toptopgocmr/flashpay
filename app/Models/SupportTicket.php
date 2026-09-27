<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';
    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }
    public function messages() { return $this->hasMany(SupportMessage::class)->orderBy('id'); }

    protected function casts(): array
    {
        return ['sla_due_at' => 'datetime'];
    }
}
