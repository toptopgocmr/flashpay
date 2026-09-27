<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'app_notifications';
    protected $guarded = ['id'];

    public function user() { return $this->belongsTo(User::class); }

    protected function casts(): array
    {
        return ['data' => 'array', 'sound' => 'boolean', 'read_at' => 'datetime', 'sms_sent_at' => 'datetime', 'push_sent_at' => 'datetime'];
    }
}
