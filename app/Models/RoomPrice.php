<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomPrice extends Model
{
    protected $fillable = [
        'room_id', 'date', 'price', 'is_available', 'available_units',
        'softblock_count', 'min_stay', 'max_stay',
        'min_advance_days', 'max_advance_days',
        'closed_to_arrival', 'closed_to_departure',
    ];

    protected $casts = [
        'date'                => 'date',
        'price'               => 'float',
        'is_available'        => 'boolean',
        'available_units'     => 'integer',
        'softblock_count'     => 'integer',
        'min_stay'            => 'integer',
        'max_stay'            => 'integer',
        'min_advance_days'    => 'integer',
        'max_advance_days'    => 'integer',
        'closed_to_arrival'   => 'boolean',
        'closed_to_departure' => 'boolean',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
