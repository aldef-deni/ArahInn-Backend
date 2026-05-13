<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelFee extends Model
{
    protected $fillable = [
        'hotel_id', 'name', 'amount', 'type', 'per', 'mandatory', 'active',
    ];

    protected $casts = [
        'amount'    => 'float',
        'mandatory' => 'boolean',
        'active'    => 'boolean',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}
