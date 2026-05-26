<?php

namespace App\Http\Controllers;

use App\Models\Booking;
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

        // Superadmin & admin (market manager): akses penuh
        if ($user->hasRole(['superadmin', 'admin'])) {
            return $hotel;
        }

        // Owner: hanya hotel miliknya
        if ($user->hasRole('owner') && (int) $hotel->owner_id === (int) $user->id) {
            return $hotel;
        }

        // Admin properti (staff hotel): akses jika ditugaskan ke hotel ini
        if ($user->hasRole('admin_property') && (int) $hotel->owner_id === (int) $user->id) {
            return $hotel;
        }

        abort(403, 'Akses ditolak.');
    }

    /** GET /hotels/{hotelId}/rooms/{roomId}/prices?year=2025&month=5
     *
     * Return per-date RoomPrice + booked_count + remaining_units supaya
     * tampilan calendar di "Atur Harga & Ketersediaan" reflect kondisi real:
     *   allotment_visible = available_units - booked_count - softblock_count
     */
    public function index(Request $request, int $hotelId, int $roomId)
    {
        $this->authorizeHotel($hotelId);

        $room = Room::where('hotel_id', $hotelId)->findOrFail($roomId);

        $year  = (int) ($request->query('year',  now()->year));
        $month = (int) ($request->query('month', now()->month));

        // Range tanggal bulan ybs (untuk filter bookings)
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        $prices = RoomPrice::where('room_id', $roomId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->keyBy(fn($p) => $p->date->format('Y-m-d'));

        // Booking yang overlap bulan ini — fetch SEMUA status terkait, lalu pisah di loop
        $bookings = Booking::where('room_id', $roomId)
            ->where(function ($q) {
                $q->whereIn('status', ['paid', 'issued', 'rescheduled'])
                  ->orWhere(function ($qq) {
                      $qq->where('status', 'pending')
                         ->where(function ($qqq) {
                             $qqq->whereNull('expires_at')
                                 ->orWhere('expires_at', '>', now());
                         });
                  });
            })
            ->where('check_in',  '<=', $end->format('Y-m-d'))
            ->where('check_out', '>',  $start->format('Y-m-d'))
            ->get(['check_in', 'check_out', 'room_count', 'booking_code', 'status']);

        // Hitung counts per tanggal: pisah confirmed (paid/issued/rescheduled) vs pending
        $totalUnits = (int) $room->total_units;
        $enriched = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $d = $cursor->format('Y-m-d');
            $row = $prices->get($d);

            $bookingsOnDate = $bookings->filter(fn($b) =>
                Carbon::parse($b->check_in)->lte($cursor) &&
                Carbon::parse($b->check_out)->gt($cursor)
            );

            // Confirmed = sudah bayar (paid/issued/rescheduled) — pasti kurangi allotment owner
            $bookedCount = $bookingsOnDate
                ->whereIn('status', ['paid', 'issued', 'rescheduled'])
                ->sum('room_count');

            // Pending = customer sudah pesan tapi belum bayar (masih dalam expires window)
            // Slot ini terkunci sementara untuk cegah double-book, tapi belum "final"
            $pendingCount = $bookingsOnDate
                ->where('status', 'pending')
                ->sum('room_count');

            $allotment      = ($row && $row->available_units !== null)
                ? (int) $row->available_units
                : $totalUnits;
            $softblock      = (int) ($row?->softblock_count ?? 0);
            // Sisa = allotment - confirmed (paid/issued) - softblock.
            // PENDING TIDAK MENGURANGI sisa — sesuai requirement owner.
            // Pending tetap tracked & dipakai oleh BookingService::assertAllotment
            // untuk cegah overbooking, tapi di display dianggap "belum final".
            $remainingUnits = max(0, $allotment - (int) $bookedCount - $softblock);

            // Kalau RoomPrice row sudah ada → enrich, kalau tidak → kembalikan default
            if ($row) {
                $arr = $row->toArray();
            } else {
                $arr = [
                    'room_id'             => $roomId,
                    'date'                => $d,
                    'price'               => null,
                    'is_available'        => true,
                    'available_units'     => null,
                    'softblock_count'     => 0,
                    'min_stay'            => null,
                    'max_stay'            => null,
                    'closed_to_arrival'   => false,
                    'closed_to_departure' => false,
                ];
            }

            $arr['allotment']       = $allotment;        // total slot di-set owner (atau total_units)
            $arr['booked_count']    = (int) $bookedCount;  // sudah bayar (paid/issued/rescheduled)
            $arr['pending_count']   = (int) $pendingCount; // menunggu pembayaran (lock sementara)
            $arr['remaining_units'] = $remainingUnits;     // sisa yang bisa dipesan customer

            $enriched[$d] = $arr;
            $cursor->addDay();
        }

        return response()->json([
            'data'        => $enriched,
            'base_price'  => (float) $room->base_price,
            'total_units' => $totalUnits,
        ]);
    }

    /** PUT /hotels/{hotelId}/rooms/{roomId}/prices — upsert array of dates */
    public function upsert(Request $request, int $hotelId, int $roomId)
    {
        $this->authorizeHotel($hotelId);

        Room::where('hotel_id', $hotelId)->findOrFail($roomId);

        $request->validate([
            'prices'                          => 'required|array',
            'prices.*.date'                   => 'required|date_format:Y-m-d',
            'prices.*.price'                  => 'nullable|numeric|min:0',
            'prices.*.is_available'           => 'sometimes|boolean',
            'prices.*.available_units'        => 'sometimes|nullable|integer|min:0|max:999',
            'prices.*.softblock_count'        => 'sometimes|integer|min:0|max:9999',
            'prices.*.min_stay'               => 'sometimes|nullable|integer|min:1|max:365',
            'prices.*.max_stay'               => 'sometimes|nullable|integer|min:1|max:365',
            'prices.*.closed_to_arrival'      => 'sometimes|boolean',
            'prices.*.closed_to_departure'    => 'sometimes|boolean',
        ]);

        foreach ($request->prices as $entry) {
            $payload = [];
            foreach (['price','is_available','available_units','softblock_count','min_stay','max_stay','closed_to_arrival','closed_to_departure'] as $f) {
                if (array_key_exists($f, $entry)) $payload[$f] = $entry[$f];
            }
            if (empty($payload)) continue;

            // Auto-derive is_available dari available_units:
            //   available_units = 0 → is_available = false (kamar tutup hari itu)
            //   available_units > 0 → is_available = true
            // is_available manual tetap dihormati kalau available_units tidak dikirim.
            if (array_key_exists('available_units', $payload) && $payload['available_units'] !== null) {
                $payload['is_available'] = ((int) $payload['available_units']) > 0;
            }

            RoomPrice::updateOrCreate(
                ['room_id' => $roomId, 'date' => $entry['date']],
                $payload
            );
        }

        return response()->json(['message' => 'Harga & ketersediaan berhasil diperbarui.']);
    }

    /**
     * GET /hotels/{hotelId}/rooms/prices/range?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&room_id=...
     *
     * Mengembalikan data per kamar × tanggal untuk tab Softblock Allotment:
     *  - price
     *  - is_available
     *  - softblock_count
     *  - booked_count (jumlah booking aktif paid|issued|pending)
     *  - min/max_stay & closed_to_arrival/departure
     */
    public function range(Request $request, int $hotelId)
    {
        $this->authorizeHotel($hotelId);

        $request->validate([
            'date_from' => 'required|date_format:Y-m-d',
            'date_to'   => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'room_id'   => 'nullable|integer',
        ]);

        $from = Carbon::parse($request->date_from);
        $to   = Carbon::parse($request->date_to);

        $rooms = Room::where('hotel_id', $hotelId)
            ->when($request->room_id, fn($q) => $q->where('id', $request->room_id))
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id','name','base_price','total_units']);

        if ($rooms->isEmpty()) {
            return response()->json(['data' => [], 'dates' => []]);
        }

        $roomIds = $rooms->pluck('id')->all();

        $prices = RoomPrice::whereIn('room_id', $roomIds)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get()
            ->groupBy('room_id');

        // Hitung jumlah booking aktif yang menempati tiap kamar × tanggal
        $bookings = Booking::whereIn('room_id', $roomIds)
            ->whereIn('status', ['pending','paid','issued'])
            ->where('check_in', '<=', $to->format('Y-m-d'))
            ->where('check_out', '>=', $from->format('Y-m-d'))
            ->get(['room_id','check_in','check_out','room_count']);

        // Susun list tanggal
        $dates = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        $data = $rooms->map(function ($room) use ($prices, $bookings, $dates) {
            $rPrices = $prices->get($room->id, collect())->keyBy(fn($p) => $p->date->format('Y-m-d'));
            $cells = [];
            foreach ($dates as $d) {
                $row = $rPrices->get($d);
                $bookedCount = $bookings
                    ->where('room_id', $room->id)
                    ->filter(fn($b) =>
                        Carbon::parse($b->check_in)->lte(Carbon::parse($d)) &&
                        Carbon::parse($b->check_out)->gt(Carbon::parse($d))
                    )
                    ->sum('room_count');

                // Allotment: pakai available_units dari room_prices kalau ada,
                // fallback ke room.total_units. Dikurangi booked + softblock untuk
                // menampilkan sisa yang benar-benar bisa dipesan.
                $allotment       = $row?->available_units !== null
                    ? (int) $row->available_units
                    : (int) $room->total_units;
                $remainingUnits  = max(0, $allotment - (int) $bookedCount - (int) ($row?->softblock_count ?? 0));

                $cells[$d] = [
                    'price'               => $row?->price ?? (float) $room->base_price,
                    'is_available'        => $row?->is_available ?? true,
                    'available_units'     => $allotment,
                    'remaining_units'     => $remainingUnits,
                    'softblock_count'     => (int) ($row?->softblock_count ?? 0),
                    'booked_count'        => (int) $bookedCount,
                    'min_stay'            => $row?->min_stay,
                    'max_stay'            => $row?->max_stay,
                    'closed_to_arrival'   => (bool) ($row?->closed_to_arrival ?? false),
                    'closed_to_departure' => (bool) ($row?->closed_to_departure ?? false),
                    'total_units'         => (int) $room->total_units,
                ];
            }
            return [
                'room_id'   => $room->id,
                'room_name' => $room->name,
                'cells'     => $cells,
            ];
        })->values();

        return response()->json([
            'dates' => $dates,
            'data'  => $data,
        ]);
    }

    /** POST /hotels/{hotelId}/rooms/prices/bulk */
    public function bulk(Request $request, int $hotelId)
    {
        $this->authorizeHotel($hotelId);

        $request->validate([
            'room_ids'        => 'required|array',
            'room_ids.*'      => 'integer',
            'date_from'       => 'required|date_format:Y-m-d',
            'date_to'         => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'price'           => 'nullable|numeric|min:0',
            'is_available'    => 'nullable|boolean',
            'available_units' => 'nullable|integer|min:0|max:999',
            'apply_days'      => 'nullable|array',
            'apply_days.*'    => 'integer|min:0|max:6',
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

        $affectedCells = 0;
        $affectedDates = 0;

        DB::transaction(function () use ($validIds, $dateFrom, $dateTo, $applyDays, $request, &$affectedCells, &$affectedDates) {
            $current = $dateFrom->copy();
            while ($current->lte($dateTo)) {
                if (in_array($current->dayOfWeek, $applyDays)) {
                    $affectedDates++;
                    foreach ($validIds as $roomId) {
                        $update = [];
                        if ($request->has('price') && $request->price !== null) {
                            $update['price'] = (float) $request->price;
                        }
                        if ($request->has('available_units') && $request->available_units !== null) {
                            $units = (int) $request->available_units;
                            $update['available_units'] = $units;
                            $update['is_available']    = $units > 0;
                        }
                        if ($request->has('is_available') && $request->is_available !== null && !array_key_exists('is_available', $update)) {
                            $update['is_available'] = (bool) $request->is_available;
                        }
                        if ($update) {
                            RoomPrice::updateOrCreate(
                                ['room_id' => $roomId, 'date' => $current->format('Y-m-d')],
                                $update
                            );
                            $affectedCells++;
                        }
                    }
                }
                $current->addDay();
            }
        });

        return response()->json([
            'message'         => 'Bulk update berhasil diterapkan.',
            'rooms'           => count($validIds),
            'dates'           => $affectedDates,
            'affected_cells'  => $affectedCells,
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
