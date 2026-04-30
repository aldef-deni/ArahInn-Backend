<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    protected $fillable = [
        'code', 'type', 'name', 'description', 'discount_type', 'discount_value',
        'min_purchase', 'max_discount', 'quota', 'used_count',
        'start_date', 'end_date', 'is_active', 'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'is_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    protected $attributes = [
        'used_count' => 0,
        'is_active' => true,
        'min_purchase' => 0,
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)
            ->where(fn($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', now()))
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    public function calculateDiscount(float $amount): float
    {
        if ($amount < $this->min_purchase) {
            return 0;
        }

        $discount = $this->discount_type === 'percent'
            ? $amount * ($this->discount_value / 100)
            : $this->discount_value;

        return $this->max_discount ? min($discount, $this->max_discount) : $discount;
    }
}
