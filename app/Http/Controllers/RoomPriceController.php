<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomPrice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomPriceController extends Controller
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

    /** GET /hotels/{hotelId}/rooms/{roomId}/prices?year=2025&month=5 */
    public function index(Request $request, int $hotelId, int $roomId)
    {
        $this->authorizeHotel($hotelId);

        $room = Room::where('hotel_id', $hotelId)->findOrFail($roomId);

        $year  = (int) ($request->query('year',  now()->year));
        $month = (int) ($request->query('month', now()->month));

        $prices = RoomPrice::where('room_id', $roomId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->keyBy(fn($p) => $p->date->format('Y-m-d'));

        return response()->json([
            'data'       => $prices,
            'base_price' => (float) $room->base_price,
        ]);
    }

    /** PUT /hotels/{hotelId}/rooms/{roomId}/prices — upsert array of dates */
    public function upsert(Request $request, int $hotelId, int $roomId)
    {
        $this->authorizeHotel($hotelId);

        Room::where('hotel_id', $hotelId)->findOrFail($roomId);

        $request->validate([
            'prices'               => 'required|array',
            'prices.*.date'        => 'required|date_format:Y-m-d',
            'prices.*.price'       => 'nullable|numeric|min:0',
            'prices.*.is_available'=> 'required|boolean',
        ]);

        foreach ($request->prices as $entry) {
            RoomPrice::updateOrCreate(
                ['room_id' => $roomId, 'date' => $entry['date']],
                [
                    'price'        => $entry['price'] ?? null,
                    'is_available' => $entry['is_available'],
                ]
            );
        }

        return response()->json(['message' => 'Harga berhasil diperbarui.']);
    }

    /** POST /hotels/{hotelId}/rooms/prices/bulk */
    public function bulk(Request $request, int $hotelId)
    {
        $this->authorizeHotel($hotelId);

        $request->validate([
            'room_ids'    => 'required|array',
            'room_ids.*'  => 'integer',
            'date_from'   => 'required|date_format:Y-m-d',
            'date_to'     => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'price'       => 'nullable|numeric|min:0',
            'is_available'=> 'nullable|boolean',
            'apply_days'  => 'nullable|array',
            'apply_days.*'=> 'integer|min:0|max:6',
        ]);

        $roomIds    = $request->room_ids;
        $applyDays  = $request->apply_days ?? [0,1,2,3,4,5,6];
        $dateFrom   = Carbon::parse($request->date_from);
        $dateTo     = Carbon::parse($request->date_to);

        // Validate rooms belong to hotel
        $validIds = Room::where('hotel_id', $hotelId)
            ->whereIn('id', $roomIds)
            ->pluck('id')
            ->toArray();

        DB::transaction(function () use ($validIds, $dateFrom, $dateTo, $applyDays, $request) {
            $current = $dateFrom->copy();
            while ($current->lte($dateTo)) {
                if (in_array($current->dayOfWeek, $applyDays)) {
                    foreach ($validIds as $roomId) {
                        $update = [];
                        if ($request->has('price') && $request->price !== null) {
                            $update['price'] = (float) $request->price;
                        }
                        if ($request->has('is_available') && $request->is_available !== null) {
                            $update['is_available'] = (bool) $request->is_available;
                        }
                        if ($update) {
                            RoomPrice::updateOrCreate(
                                ['room_id' => $roomId, 'date' => $current->format('Y-m-d')],
                                $update
                            );
                        }
                    }
                }
                $current->addDay();
            }
        });

        return response()->json([
            'message' => 'Bulk update berhasil diterapkan.',
            'rooms'   => count($validIds),
        ]);
    }

    /** PUT /hotels/{hotelId}/rooms/{roomId}/toggle-now — toggle is_active for today */
    public function toggleNow(Request $request, int $hotelId, int $roomId)
    {
        $this->authorizeHotel($hotelId);

        $room = Room::where('hotel_id', $hotelId)->findOrFail($roomId);
        $room->update(['is_active' => !$room->is_active]);

        return response()->json([
            'data'    => ['id' => $room->id, 'is_active' => $room->is_active],
            'message' => $room->is_active ? 'Kamar dibuka kembali.' : 'Kamar ditutup sementara.',
        ]);
    }
}
