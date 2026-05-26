<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RatePlan extends Model
{
    protected $fillable = [
        'hotel_id', 'parent_rate_plan_id', 'discount_percent',
        'name', 'description', 'type',
        'min_nights', 'max_nights',
        'meal_plan', 'meal_options',
        'breakfast', 'cancelable',
        'cancellation_type', 'cancellation_detail',
        'tariff_mode',
        'booking_period', 'stay_period', 'advance_booking',
        'blackout_enabled', 'blackout_dates',
        'child_pricing_enabled',
        'target_settings',
        'room_ids',
        'multiplier', 'active', 'is_default',
    ];

    protected $casts = [
        'breakfast'             => 'boolean',
        'cancelable'            => 'boolean',
        'blackout_enabled'      => 'boolean',
        'child_pricing_enabled' => 'boolean',
        'active'                => 'boolean',
        'is_default'            => 'boolean',
        'multiplier'            => 'float',
        'discount_percent'      => 'float',
        'meal_options'          => 'array',
        'cancellation_detail'   => 'array',
        'blackout_dates'        => 'array',
        'target_settings'       => 'array',
        'room_ids'              => 'array',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function parentPlan()
    {
        return $this->belongsTo(RatePlan::class, 'parent_rate_plan_id');
    }

    public function childPlans()
    {
        return $this->hasMany(RatePlan::class, 'parent_rate_plan_id');
    }

    /**
     * Pilih rate plan yang berlaku untuk sebuah room + tanggal booking.
     *
     * Urutan prioritas:
     *  1. Custom (non-default) yang match room_ids + min/max nights + tidak blackout.
     *  2. Default plan dari hotel (kalau ada).
     *  3. null — caller harus fallback ke harga dasar room.
     */
    public static function pickApplicable(int $hotelId, int $roomId, int $nights, string $checkIn, string $checkOut): ?self
    {
        $plans = static::where('hotel_id', $hotelId)
            ->where('active', true)
            ->orderByDesc('is_default')      // default last (kita filter setelahnya)
            ->orderBy('created_at')
            ->get();

        $matchesRoom = function (self $plan) use ($roomId) {
            $ids = $plan->room_ids;
            return empty($ids) || in_array($roomId, (array) $ids, true);
        };

        $matchesNights = function (self $plan) use ($nights) {
            $min = (int) ($plan->min_nights ?? 1);
            $max = $plan->max_nights ? (int) $plan->max_nights : null;
            if ($nights < $min) return false;
            if ($max !== null && $nights > $max) return false;
            return true;
        };

        $matchesBlackout = function (self $plan) use ($checkIn, $checkOut) {
            if (!$plan->blackout_enabled || empty($plan->blackout_dates)) return true;
            $ci = \Carbon\Carbon::parse($checkIn);
            $co = \Carbon\Carbon::parse($checkOut);
            foreach ((array) $plan->blackout_dates as $d) {
                try {
                    $dd = \Carbon\Carbon::parse($d);
                    if ($dd->betweenIncluded($ci, $co->copy()->subDay())) {
                        return false;
                    }
                } catch (\Throwable) {}
            }
            return true;
        };

        // 1. cari custom (non-default) yang match
        foreach ($plans as $plan) {
            if ($plan->is_default) continue;
            if ($matchesRoom($plan) && $matchesNights($plan) && $matchesBlackout($plan)) {
                return $plan;
            }
        }

        // 2. fallback ke default plan jika ada
        foreach ($plans as $plan) {
            if (!$plan->is_default) continue;
            if ($matchesRoom($plan) && $matchesBlackout($plan)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Hitung modifier harga: multiplier × (1 − discount_percent/100)
     */
    public function priceModifier(): float
    {
        $mult     = (float) ($this->multiplier ?? 1.0);
        $discount = (float) ($this->discount_percent ?? 0);
        return $mult * (1 - max(0, min(100, $discount)) / 100);
    }
}
