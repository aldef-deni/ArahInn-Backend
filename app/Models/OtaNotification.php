<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtaNotification extends Model
{
    protected $table = 'ota_notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'data', 'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($q)
    {
        return $q->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
