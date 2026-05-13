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

        if ($user->role !== 'superadmin' && $user->role !== 'admin') {
            abort_if($hotel->owner_id !== $user->id, 403, 'Akses ditolak.');
        }

        return $hotel;
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
            'child_policy.free_under_age'     => 'integer|min:0|max:18',
            'child_policy.max_child_age'      => 'integer|min:0|max:18',
            'child_policy.child_free_policy'  => 'string|in:share_bed,all,none',
            'child_policy.child_discount'     => 'numeric|min:0|max:100',
            'child_policy.extra_bed_charge'   => 'numeric|min:0',
        ]);

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
