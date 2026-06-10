<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Promo;
use App\Models\Room;
use App\Models\RoomPrice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    public function byHotel(string $hotelId)
    {
        // Override base_price tiap kamar pakai harga effective dari room_prices
        // hari ini (atau check_in param), supaya konsisten dengan harga di home/detail.
        $priceDate = request()->input('check_in') ?: now()->format('Y-m-d');

        $rooms = Room::where('hotel_id', $hotelId)->active()->get()->map(function ($room) use ($priceDate) {
            $override = RoomPrice::where('room_id', $room->id)
                ->whereDate('date', $priceDate)
                ->whereNotNull('price')
                ->value('price');
            $arr = $room->toArray();
            $arr['base_price']    = (float) ($override ?? $room->base_price);
            $arr['default_price'] = (float) $room->base_price;
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $rooms]);
    }

    public function availability(Request $request, string $hotelId)
    {
        $request->validate([
            'check_in'  => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $hotel   = Hotel::select('id', 'owner_id')->find($hotelId);
        $ownerId = $hotel?->owner_id;

        $checkIn  = Carbon::parse($request->check_in);
        $checkOut = Carbon::parse($request->check_out);

        // List tanggal stay (check_in inclusive, check_out exclusive — pola hotel)
        $stayDates = [];
        $cursor = $checkIn->copy();
        while ($cursor->lt($checkOut)) {
            $stayDates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        $rooms = Room::where('hotel_id', $hotelId)->active()->get()->map(function ($room) use ($request, $ownerId, $stayDates) {
            // Ambil semua room_prices yang overlap rentang stay
            $prices = RoomPrice::where('room_id', $room->id)
                ->whereIn('date', $stayDates)
                ->get()
                ->keyBy(fn($p) => $p->date->format('Y-m-d'));

            // Hitung allotment minimum & cek penutupan eksplisit.
            // Initial: null supaya nilai pertama jadi referensi (bukan total_units
            // yang bisa lebih kecil dari allotment owner — bug sebelumnya).
            $minAllotment = null;
            $explicitlyClosedOn = null;

            foreach ($stayDates as $d) {
                $row = $prices->get($d);

                if ($row && $row->is_available === false) {
                    $explicitlyClosedOn = $d;
                    break;
                }

                $allotmentForDay = ($row && $row->available_units !== null)
                    ? (int) $row->available_units
                    : (int) $room->total_units;

                if ($minAllotment === null || $allotmentForDay < $minAllotment) {
                    $minAllotment = $allotmentForDay;
                }
            }

            // Kalau loop tidak punya iterasi (mis. stayDates kosong), fallback ke total_units
            if ($minAllotment === null) {
                $minAllotment = (int) $room->total_units;
            }

            // Hitung booking aktif yang overlap rentang
            // Hanya hitung booking aktif yang valid:
            //  - paid & issued: pasti terhitung
            //  - pending: hanya yang belum melewati expires_at (default 30 menit)
            $booked = Booking::where('room_id', $room->id)
                ->where(function ($q) {
                    $q->whereIn('status', ['paid', 'issued'])
                      ->orWhere(function ($qq) {
                          $qq->where('status', 'pending')
                             ->where(function ($qqq) {
                                 $qqq->whereNull('expires_at')
                                     ->orWhere('expires_at', '>', now());
                             });
                      });
                })
                ->where('check_in',  '<', $request->check_out)
                ->where('check_out', '>', $request->check_in)
                ->sum('room_count');

            $remaining = max(0, $minAllotment - (int) $booked);
            $isAvailable = $explicitlyClosedOn === null && $remaining > 0;

            // Override base_price kalau owner set harga khusus per tanggal — pakai max
            // dari range (untuk display "mulai dari" konservatif kita pakai min)
            $minPriceInRange = null;
            foreach ($stayDates as $d) {
                $row = $prices->get($d);
                $p = $row?->price ?? (float) $room->base_price;
                if ($minPriceInRange === null || $p < $minPriceInRange) {
                    $minPriceInRange = $p;
                }
            }

            $effective = $minPriceInRange ?? (float) $room->base_price;

            // Override base_price ke harga effective (per-tanggal kalau ada, fallback default)
            // supaya frontend yang baca room.basePrice langsung dapat harga aktual.
            $payload = array_merge($room->toArray(), [
                'base_price'       => $effective,
                'default_price'    => (float) $room->base_price,
                'available'        => $isAvailable,
                'booked_units'     => (int) $booked,
                'available_units'  => $minAllotment,
                'remaining_units'  => $remaining,
                'closed_on'        => $explicitlyClosedOn,
                'effective_price'  => $effective,
            ]);

            // Tempel info diskon yang diikuti owner (promo platform ATAU campaign)
            if ($ownerId) {
                $best = \App\Services\OwnerDiscountService::best($ownerId, $effective);
                if ($best) {
                    $payload['applied_promo']    = $best['applied'];
                    $payload['discount_source']  = $best['source'];
                    $payload['original_price']   = $effective;
                    $payload['discounted_price'] = $best['final'];
                    $payload['discount_amount']  = $best['discount'];
                }
            }

            return $payload;
        });

        return response()->json(['success' => true, 'data' => $rooms]);
    }

    public function store(Request $request, string $hotelId)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string',
            'base_price' => 'required|numeric|min:0',
            'max_guests' => 'required|integer|min:1',
            'total_units' => 'required|integer|min:1',
            'facilities' => 'nullable|array',
            'description' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'string',
            'image_files' => 'nullable|array',
            'image_files.*' => 'file|mimes:jpg,jpeg|max:5120|dimensions:min_width=800,min_height=800',
        ]);

        $data['hotel_id'] = $hotelId;
        $data['images'] = $this->mergeRoomImages($request, $data['images'] ?? []);
        $room = Room::create($data);

        return response()->json(['success' => true, 'data' => $room], 201);
    }

    public function update(Request $request, string $hotelId, string $roomId)
    {
        $room = Room::where('hotel_id', $hotelId)->findOrFail($roomId);

        $data = $request->validate([
            'name' => 'sometimes|string',
            'type' => 'sometimes|string',
            'base_price' => 'sometimes|numeric|min:0',
            'max_guests' => 'sometimes|integer|min:1',
            'total_units' => 'sometimes|integer|min:1',
            'facilities' => 'nullable|array',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'images' => 'nullable|array',
            'images.*' => 'string',
            'existing_images' => 'nullable|array',
            'existing_images.*' => 'string',
            'image_files' => 'nullable|array',
            'image_files.*' => 'file|mimes:jpg,jpeg|max:5120|dimensions:min_width=800,min_height=800',
        ]);

        if ($request->has('images') || $request->has('existing_images') || $request->hasFile('image_files')) {
            $baseImages = $data['existing_images'] ?? $data['images'] ?? [];
            $data['images'] = $this->mergeRoomImages($request, $baseImages);
        }

        unset($data['existing_images'], $data['image_files']);

        $room->update($data);

        return response()->json(['success' => true, 'data' => $room]);
    }

    public function destroy(string $hotelId, string $roomId)
    {
        $room = Room::where('hotel_id', $hotelId)->findOrFail($roomId);
        $room->update(['is_active' => false]);

        return response()->json(['success' => true, 'message' => 'Kamar dinonaktifkan.']);
    }

    private function mergeRoomImages(Request $request, array $existingImages): array
    {
        $images = array_values(array_filter($existingImages));

        if (!$request->hasFile('image_files')) {
            return $images;
        }

        foreach ($request->file('image_files') as $file) {
            if (!$file || !$file->isValid()) {
                continue;
            }

            try {
                $images[] = $this->storeRoomImageLocally($file);
            } catch (\Throwable $e) {
                Log::error('Room image upload failed', [
                    'message' => $e->getMessage(),
                    'file_name' => $file->getClientOriginalName(),
                ]);

                throw new \RuntimeException('Upload foto kamar gagal. Periksa izin folder upload server.');
            }
        }

        return array_values(array_unique($images));
    }

    private function storeRoomImageLocally($file): string
    {
        $directory = storage_path('app/public/uploads/rooms');
        if (!is_dir($directory)) mkdir($directory, 0755, true);

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename  = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $extension;

        $oldUmask = umask(0022);
        $file->move($directory, $filename);
        umask($oldUmask);
        @chmod($directory . DIRECTORY_SEPARATOR . $filename, 0644);

        return 'uploads/rooms/' . $filename;
    }
}
