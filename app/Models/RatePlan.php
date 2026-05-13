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
        'multiplier', 'active',
    ];

    protected $casts = [
        'breakfast'             => 'boolean',
        'cancelable'            => 'boolean',
        'blackout_enabled'      => 'boolean',
        'child_pricing_enabled' => 'boolean',
        'active'                => 'boolean',
        'multiplier'            => 'float',
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
}
