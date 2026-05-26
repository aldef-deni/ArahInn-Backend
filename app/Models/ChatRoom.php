<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRoom extends Model
{
    protected $fillable = ['booking_id', 'user_id', 'hotel_id', 'type', 'is_closed'];

    protected $casts = [
        'is_closed'    => 'boolean',
        'unread_count' => 'integer', // dari withCount() agar selalu int (bukan string)
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }
}
