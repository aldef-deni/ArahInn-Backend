<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    protected $fillable = [
        'code', 'type', 'name', 'description', 'image',
        'discount_type', 'discount_value',
        'min_purchase', 'max_discount', 'quota', 'used_count',
        'start_date', 'end_date', 'is_active', 'created_by', 'owner_id',
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

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'promo_followers', 'promo_id', 'owner_id')->withTimestamps();
    }

    public function isFollowedBy(int $ownerId): bool
    {
        return $this->followers()->where('users.id', $ownerId)->exists();
    }

    /**
     * Cari promo terbaik yang sudah di-follow oleh owner & cocok untuk amount tertentu.
     * Mengembalikan ['promo' => Promo, 'discount' => float, 'final' => float] atau null.
     */
    public static function bestForOwner(int $ownerId, float $amount): ?array
    {
        $now = now();
        $promos = self::query()
            ->whereNull('owner_id')                          // hanya promo platform
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $now))
            ->whereHas('followers', fn ($q) => $q->where('users.id', $ownerId))
            ->where(fn ($q) => $q->whereNull('quota')->orWhereColumn('used_count', '<', 'quota'))
            ->get();

        $best = null;
        foreach ($promos as $p) {
            if ($amount < (float) $p->min_purchase) continue;
            $disc = $p->calculateDiscount($amount);
            if ($disc <= 0) continue;
            if (!$best || $disc > $best['discount']) {
                $best = [
                    'promo'    => $p,
                    'discount' => round($disc, 2),
                    'final'    => round(max(0, $amount - $disc), 2),
                ];
            }
        }
        return $best;
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
