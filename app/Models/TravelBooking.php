<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelBooking extends Model
{
    protected $fillable = [
        'user_id', 'moda', 'product_code', 'code', 'group_code', 'leg',
        'vendor_booking_code', 'vendor_transaction_id', 'airline',
        'origin', 'destination', 'origin_name', 'destination_name',
        'depart_date', 'depart_time', 'arrive_time', 'service_name', 'class',
        'passengers', 'pax', 'vendor_price', 'markup', 'total_price',
        'promo_id', 'promo_discount',
        'status', 'payment_method', 'time_limit', 'paid_at', 'issued_at',
        'url_etiket', 'url_struk', 'url_image', 'meta',
    ];

    protected $casts = [
        'passengers'  => 'array',
        'meta'        => 'array',
        'depart_date' => 'date',
        'time_limit'  => 'datetime',
        'paid_at'     => 'datetime',
        'issued_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateCode(): string
    {
        return 'TRV' . now()->format('ymd') . strtoupper(substr(uniqid(), -5));
    }
}
