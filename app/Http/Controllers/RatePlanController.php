<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\RatePlan;
use Illuminate\Http\Request;

class RatePlanController extends Controller
{
    private function authorizeHotel(int $hotelId): Hotel
    {
        $hotel = Hotel::findOrFail($hotelId);
        $user  = auth()->user();

        if ($user->hasRole(['superadmin', 'admin'])) {
            return $hotel;
        }
        if ($user->hasRole(['owner', 'admin_property']) && (int) $hotel->owner_id === (int) $user->id) {
            return $hotel;
        }

        abort(403, 'Akses ditolak.');
    }

    public function index(int $hotelId)
    {
        $this->authorizeHotel($hotelId);

        $plans = RatePlan::where('hotel_id', $hotelId)
            ->with('parentPlan:id,name')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $plans]);
    }

    public function show(int $hotelId, int $planId)
    {
        $this->authorizeHotel($hotelId);

        $plan = RatePlan::where('hotel_id', $hotelId)
            ->with('parentPlan:id,name')
            ->findOrFail($planId);

        return response()->json(['data' => $plan]);
    }

    public function store(Request $request, int $hotelId)
    {
        $this->authorizeHotel($hotelId);

        $data = $request->validate([
            'name'                   => 'required|string|max:150',
            'description'            => 'nullable|string',
            'type'                   => 'in:custom,mingguan,bulanan',
            'parent_rate_plan_id'    => 'nullable|integer|exists:rate_plans,id',
            'discount_percent'       => 'nullable|numeric|min:0|max:100',
            'min_nights'            => 'integer|min:1',
            'max_nights'            => 'nullable|integer|min:1',
            'meal_plan'             => 'in:none,available',
            'meal_options'          => 'nullable|array',
            'meal_options.*'        => 'string',
            'breakfast'             => 'boolean',
            'cancelable'            => 'boolean',
            'cancellation_type'     => 'in:no_refund,custom',
            'cancellation_detail'   => 'nullable|array',
            'tariff_mode'           => 'in:property,static',
            'booking_period'        => 'in:anytime,specific',
            'stay_period'           => 'in:anytime,specific',
            'advance_booking'       => 'in:anytime,specific',
            'blackout_enabled'      => 'boolean',
            'blackout_dates'        => 'nullable|array',
            'child_pricing_enabled' => 'boolean',
            'target_settings'       => 'nullable|array',
            'room_ids'              => 'nullable|array',
            'room_ids.*'            => 'integer',
            'multiplier'            => 'numeric|min:0.1|max:10',
            'active'                => 'boolean',
            'is_default'            => 'boolean',
        ]);

        // derive breakfast from meal_options for backward compat
        if (isset($data['meal_options'])) {
            $data['breakfast'] = in_array('sarapan', $data['meal_options']);
        }

        // cancelable = true when cancellation_type is custom
        if (isset($data['cancellation_type'])) {
            $data['cancelable'] = $data['cancellation_type'] === 'custom';
        }

        // Auto-default kalau ini rate plan pertama untuk hotel ini
        $hasAnyPlan = RatePlan::where('hotel_id', $hotelId)->exists();
        if (!$hasAnyPlan) {
            $data['is_default'] = true;
        }

        // Pastikan hanya 1 default per hotel
        if (!empty($data['is_default'])) {
            RatePlan::where('hotel_id', $hotelId)->update(['is_default' => false]);
        }

        $plan = RatePlan::create(['hotel_id' => $hotelId] + $data);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, int $hotelId, int $planId)
    {
        $this->authorizeHotel($hotelId);

        $plan = RatePlan::where('hotel_id', $hotelId)->findOrFail($planId);

        $data = $request->validate([
            'name'                   => 'sometimes|string|max:150',
            'description'            => 'nullable|string',
            'type'                   => 'sometimes|in:custom,mingguan,bulanan',
            'parent_rate_plan_id'    => 'nullable|integer|exists:rate_plans,id',
            'discount_percent'       => 'nullable|numeric|min:0|max:100',
            'min_nights'            => 'sometimes|integer|min:1',
            'max_nights'            => 'nullable|integer|min:1',
            'meal_plan'             => 'sometimes|in:none,available',
            'meal_options'          => 'nullable|array',
            'meal_options.*'        => 'string',
            'breakfast'             => 'sometimes|boolean',
            'cancelable'            => 'sometimes|boolean',
            'cancellation_type'     => 'sometimes|in:no_refund,custom',
            'cancellation_detail'   => 'nullable|array',
            'tariff_mode'           => 'sometimes|in:property,static',
            'booking_period'        => 'sometimes|in:anytime,specific',
            'stay_period'           => 'sometimes|in:anytime,specific',
            'advance_booking'       => 'sometimes|in:anytime,specific',
            'blackout_enabled'      => 'sometimes|boolean',
            'blackout_dates'        => 'nullable|array',
            'child_pricing_enabled' => 'sometimes|boolean',
            'target_settings'       => 'nullable|array',
            'room_ids'              => 'nullable|array',
            'room_ids.*'            => 'integer',
            'multiplier'            => 'sometimes|numeric|min:0.1|max:10',
            'active'                => 'sometimes|boolean',
            'is_default'            => 'sometimes|boolean',
        ]);

        if (isset($data['meal_options'])) {
            $data['breakfast'] = in_array('sarapan', $data['meal_options']);
        }

        if (isset($data['cancellation_type'])) {
            $data['cancelable'] = $data['cancellation_type'] === 'custom';
        }

        // Jika set sebagai default, unset default lain di hotel ini
        if (array_key_exists('is_default', $data) && $data['is_default']) {
            RatePlan::where('hotel_id', $hotelId)
                ->where('id', '!=', $plan->id)
                ->update(['is_default' => false]);
        }

        $plan->update($data);

        // Jangan biarkan hotel tanpa default — kalau plan ini diturunkan dari default
        // dan tidak ada default lain, set default ke plan tertua yang aktif.
        if (array_key_exists('is_default', $data) && !$data['is_default'] && $plan->wasChanged('is_default')) {
            $hasDefault = RatePlan::where('hotel_id', $hotelId)->where('is_default', true)->exists();
            if (!$hasDefault) {
                $fallback = RatePlan::where('hotel_id', $hotelId)
                    ->where('active', true)
                    ->orderBy('created_at')
                    ->first();
                if ($fallback) $fallback->update(['is_default' => true]);
            }
        }

        return response()->json(['data' => $plan->fresh()]);
    }

    public function destroy(int $hotelId, int $planId)
    {
        $this->authorizeHotel($hotelId);

        $plan = RatePlan::where('hotel_id', $hotelId)->findOrFail($planId);
        $wasDefault = (bool) $plan->is_default;
        $plan->delete();

        // Promosikan plan tertua jadi default kalau yang dihapus adalah default
        if ($wasDefault) {
            $fallback = RatePlan::where('hotel_id', $hotelId)
                ->where('active', true)
                ->orderBy('created_at')
                ->first();
            if ($fallback) $fallback->update(['is_default' => true]);
        }

        return response()->json(['message' => 'Rate plan dihapus.']);
    }
}
