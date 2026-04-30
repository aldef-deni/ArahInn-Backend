<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HotelController extends Controller
{
    public function search(Request $request)
    {
        $query = Hotel::approved()
            ->with(['rooms' => fn($q) => $q->active()->orderBy('base_price')->limit(1)])
            ->withCount('bookings');

        if ($city = $request->city) {
            $query->where('city', 'like', "%{$city}%");
        }
        if ($star = $request->star_rating) {
            $query->where('star_rating', $star);
        }

        $perPage = (int) ($request->limit ?? 12);
        $result  = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'pagination' => [
                'total' => $result->total(),
                'page' => $result->currentPage(),
                'limit' => $perPage,
                'total_pages' => $result->lastPage(),
            ],
        ]);
    }

    public function show(string $id)
    {
        $hotel = Hotel::with(['owner:id,name,email,phone', 'rooms' => fn($q) => $q->active()])
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $hotel]);
    }

    public function cities()
    {
        $cities = Hotel::approved()->distinct()->pluck('city')->sort()->values();
        return response()->json(['success' => true, 'data' => $cities]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'address' => 'required|string',
            'city' => 'required|string',
            'province' => 'nullable|string',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'facilities' => 'nullable|array',
        ]);

        $data['owner_id'] = $request->user()->id;
        $data['slug'] = Str::slug($data['name']) . '-' . time();
        $data['status'] = 'pending';

        $hotel = Hotel::create($data);

        ActivityLogService::log($request->user()->id, 'CREATE_HOTEL', 'hotel', $hotel->id, $request);

        return response()->json([
            'success' => true,
            'message' => 'Hotel berhasil ditambahkan, menunggu persetujuan admin.',
            'data' => $hotel,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        $user = $request->user();

        $isSuperadmin = $user->hasRole('superadmin');
        $isHotelOwner = $user->hasRole('owner') && (int) $hotel->owner_id === (int) $user->id;

        if (!$isSuperadmin && !$isHotelOwner) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'address' => 'sometimes|string',
            'city' => 'sometimes|string',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'facilities' => 'nullable|array',
        ]);

        $hotel->update($data);

        return response()->json(['success' => true, 'data' => $hotel]);
    }

    public function approve(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        $hotel->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        ActivityLogService::log($request->user()->id, 'APPROVE_HOTEL', 'hotel', $id, $request);

        return response()->json(['success' => true, 'message' => 'Hotel disetujui.', 'data' => $hotel]);
    }

    public function block(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        $hotel->update(['status' => 'blocked']);
        ActivityLogService::log($request->user()->id, 'BLOCK_HOTEL', 'hotel', $id, $request);

        return response()->json(['success' => true, 'message' => 'Hotel diblokir.']);
    }

    public function myHotel(Request $request)
    {
        $hotel = Hotel::where('owner_id', $request->user()->id)
            ->with(['rooms' => fn($q) => $q->active()])
            ->first();

        if (!$hotel) {
            return response()->json(['success' => false, 'message' => 'Hotel tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $hotel]);
    }
}
