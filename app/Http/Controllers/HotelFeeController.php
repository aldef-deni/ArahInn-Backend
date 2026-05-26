<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\HotelFee;
use Illuminate\Http\Request;

class HotelFeeController extends Controller
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

        $fees = HotelFee::where('hotel_id', $hotelId)->orderBy('name')->get();

        return response()->json(['data' => $fees]);
    }

    public function store(Request $request, int $hotelId)
    {
        $this->authorizeHotel($hotelId);

        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'category'   => 'nullable|string|max:100',
            'amount'     => 'required|numeric|min:0',
            'type'       => 'required|in:fixed,percent',
            'per'        => 'required|in:night,stay,person',
            'mandatory'  => 'boolean',
            'active'     => 'boolean',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $fee = HotelFee::create(['hotel_id' => $hotelId] + $data);

        return response()->json(['data' => $fee], 201);
    }

    public function update(Request $request, int $hotelId, int $feeId)
    {
        $this->authorizeHotel($hotelId);

        $fee = HotelFee::where('hotel_id', $hotelId)->findOrFail($feeId);

        $data = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'category'   => 'sometimes|nullable|string|max:100',
            'amount'     => 'sometimes|numeric|min:0',
            'type'       => 'sometimes|in:fixed,percent',
            'per'        => 'sometimes|in:night,stay,person',
            'mandatory'  => 'sometimes|boolean',
            'active'     => 'sometimes|boolean',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'end_date'   => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $fee->update($data);

        return response()->json(['data' => $fee]);
    }

    public function destroy(int $hotelId, int $feeId)
    {
        $this->authorizeHotel($hotelId);

        $fee = HotelFee::where('hotel_id', $hotelId)->findOrFail($feeId);
        $fee->delete();

        return response()->json(['message' => 'Biaya dihapus.']);
    }
}
