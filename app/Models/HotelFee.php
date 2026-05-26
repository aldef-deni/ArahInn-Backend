<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelFee extends Model
{
    protected $fillable = [
        'hotel_id', 'name', 'category', 'amount', 'type', 'per',
        'mandatory', 'active', 'start_date', 'end_date',
    ];

    protected $casts = [
        'amount'     => 'float',
        'mandatory'  => 'boolean',
        'active'     => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}
