<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    public function byHotel(string $hotelId)
    {
        $rooms = Room::where('hotel_id', $hotelId)->active()->get();
        return response()->json(['success' => true, 'data' => $rooms]);
    }

    public function availability(Request $request, string $hotelId)
    {
        $request->validate([
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $rooms = Room::where('hotel_id', $hotelId)->active()->get()->map(function ($room) use ($request) {
            $booked = Booking::where('room_id', $room->id)
                ->whereIn('status', ['paid', 'issued', 'pending'])
                ->where('check_in', '<', $request->check_out)
                ->where('check_out', '>', $request->check_in)
                ->count();

            return array_merge($room->toArray(), [
                'available' => $booked < $room->total_units,
                'booked_units' => $booked,
            ]);
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
            'image_files.*' => 'image|max:5120',
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
            'image_files.*' => 'image|max:5120',
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
        $directory = public_path('uploads/rooms');

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Folder upload rooms tidak bisa dibuat.');
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $extension;

        $file->move($directory, $filename);

        return asset('uploads/rooms/' . $filename);
    }
}
