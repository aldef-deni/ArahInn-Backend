<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use Illuminate\Http\Request;

class HotelSettingsController extends Controller
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

    public function show(int $hotelId)
    {
        $hotel = $this->authorizeHotel($hotelId);

        return response()->json([
            'data' => [
                'pricing_model' => $hotel->pricing_model ?? 'room',
                'child_policy'  => $hotel->child_policy,
            ],
        ]);
    }

    public function update(Request $request, int $hotelId)
    {
        $hotel = $this->authorizeHotel($hotelId);

        $data = $request->validate([
            'pricing_model'                   => 'sometimes|in:room,occupancy',
            'child_policy'                    => 'nullable|array',
            // legacy fields
            'child_policy.free_under_age'     => 'sometimes|integer|min:0|max:18',
            'child_policy.max_child_age'      => 'sometimes|integer|min:0|max:18',
            'child_policy.child_free_policy'  => 'sometimes|string|in:share_bed,all,none',
            'child_policy.child_discount'     => 'sometimes|numeric|min:0|max:100',
            'child_policy.extra_bed_charge'   => 'sometimes|numeric|min:0',
            // tiket.com-style fields
            'child_policy.charge_mode'        => 'sometimes|string|in:free,paid',
            'child_policy.age_groups'         => 'sometimes|array',
            'child_policy.age_groups.*.id'        => 'sometimes',
            'child_policy.age_groups.*.label'     => 'sometimes|string|max:100',
            'child_policy.age_groups.*.min_age'   => 'sometimes|integer|min:0|max:18',
            'child_policy.age_groups.*.max_age'   => 'sometimes|integer|min:0|max:18',
            'child_policy.prices'             => 'sometimes|array',
        ]);

        // Merge dengan child_policy lama supaya tidak ada field yang hilang ketika partial save
        if (array_key_exists('child_policy', $data) && is_array($hotel->child_policy)) {
            $data['child_policy'] = array_replace($hotel->child_policy, $data['child_policy'] ?? []);
        }

        $hotel->update($data);

        return response()->json([
            'data'    => [
                'pricing_model' => $hotel->pricing_model,
                'child_policy'  => $hotel->child_policy,
            ],
            'message' => 'Pengaturan berhasil disimpan.',
        ]);
    }
}
