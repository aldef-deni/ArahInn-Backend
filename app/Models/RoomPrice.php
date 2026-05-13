<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomPrice extends Model
{
    protected $fillable = ['room_id', 'date', 'price', 'is_available'];

    protected $casts = [
        'date'         => 'date',
        'price'        => 'float',
        'is_available' => 'boolean',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
