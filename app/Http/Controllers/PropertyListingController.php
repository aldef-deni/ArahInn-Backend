<?php

namespace App\Http\Controllers;

use App\Models\PropertyListing;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class PropertyListingController extends Controller
{
    // ── Public: list approved listings with filters ───────────────────────
    public function index(Request $request)
    {
        $query = PropertyListing::with('owner:id,name,email')
            ->orderByDesc('created_at');

        // Status filter (superadmin can pass 'all', 'pending', 'approved', 'rejected')
        $status = $request->status ?? 'approved';
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($city = $request->city) {
            $query->where('city', 'like', "%{$city}%");
        }
        if ($category = $request->category) {
            $query->where('category', $category);
        }
        if ($listingType = $request->listing_type) {
            $query->where('listing_type', $listingType);
        }
        if ($minPrice = $request->min_price) {
            $query->where('price', '>=', $minPrice);
        }
        if ($maxPrice = $request->max_price) {
            $query->where('price', '<=', $maxPrice);
        }

        // "Lokasi terdekat" — urutkan dari yang paling dekat dgn koordinat customer
        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = (float) $request->lat;
            $lng = (float) $request->lng;
            $hav = "(6371 * acos(LEAST(1, cos(radians($lat)) * cos(radians(latitude))"
                 . " * cos(radians(longitude) - radians($lng)) + sin(radians($lat)) * sin(radians(latitude)))))";
            $query->whereNotNull('latitude')->whereNotNull('longitude')
                  ->addSelect(\Illuminate\Support\Facades\DB::raw("$hav AS distance_km"))
                  ->reorder()->orderBy('distance_km');
        }

        $perPage  = (int) ($request->limit ?? 12);
        $listings = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $listings->items(),
            'pagination' => [
                'total'       => $listings->total(),
                'totalPages'  => $listings->lastPage(),
                'currentPage' => $listings->currentPage(),
            ],
        ]);
    }

    // ── Public: single listing detail, increment views_count ─────────────
    public function show(string $id)
    {
        $listing = PropertyListing::with('owner:id,name,email,phone')->findOrFail($id);

        // Only increment views on approved listings for public access
        if ($listing->status === 'approved') {
            $listing->increment('views_count');
        }

        return response()->json(['success' => true, 'data' => $listing]);
    }

    // ── Owner: create new listing, notify superadmin ──────────────────────
    public function store(Request $request)
    {
        $user = auth('sanctum')->user();

        $data = $request->validate([
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string',
            'category'        => 'required|string|in:Hotel,Apartment,Kosan,Guest House,Villa,Resort',
            'listing_type'    => 'nullable|string|in:sell,rent',
            'price'           => 'required|integer|min:1',
            'price_negotiable'=> 'nullable',
            'address'         => 'nullable|string',
            'city'            => 'required|string|max:100',
            'province'        => 'nullable|string|max:100',
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
            'land_area'       => 'nullable|integer|min:1',
            'building_area'   => 'nullable|integer|min:1',
            'bedrooms'        => 'nullable|integer|min:0|max:255',
            'bathrooms'       => 'nullable|integer|min:0|max:255',
            'certificate'     => 'nullable|string|in:SHM,HGB,Strata,Lainnya',
            'facilities'      => 'nullable|array',
            'contact_phone'   => 'nullable|string|max:30',
            'contact_email'   => 'nullable|email|max:100',
            'images.*'        => 'nullable|image|mimes:jpg,jpeg|max:5120|dimensions:min_width=800,min_height=800',
        ], \App\Http\Controllers\InteriorDesignController::uploadMessages(800));

        $data['owner_id']         = $user->id;
        $data['status']           = 'pending';
        $data['price_negotiable'] = filter_var($request->input('price_negotiable', false), FILTER_VALIDATE_BOOLEAN);

        if ($request->hasFile('images')) {
            $data['images'] = $this->applyPrimaryImage(
                $this->uploadImages($request, []),
                $request->input('primary_index')
            );
        }

        if (isset($data['facilities']) && is_string($data['facilities'])) {
            $data['facilities'] = json_decode($data['facilities'], true) ?? [];
        }

        $listing = PropertyListing::create($data);

        NotificationService::sendToRoles(['superadmin', 'admin'], 'property_listing_new',
            'Listing Properti Baru',
            "Listing \"{$listing->title}\" didaftarkan oleh {$user->name} dan menunggu persetujuan.",
            ['listing_id' => $listing->id, 'listing_title' => $listing->title]
        );

        return response()->json([
            'success' => true,
            'message' => 'Listing properti berhasil dikirim, menunggu persetujuan admin.',
            'data'    => $listing,
        ], 201);
    }

    // ── Owner: update own listing (only if pending/rejected) ─────────────
    public function update(Request $request, string $id)
    {
        $user    = auth('sanctum')->user();
        $listing = PropertyListing::findOrFail($id);

        $isSuperadmin = $user->hasRole('superadmin') || $user->hasRole('admin');
        $isOwner      = $user->hasRole('owner') && (int) $listing->owner_id === (int) $user->id;

        if (!$isSuperadmin && !$isOwner) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }


        $data = $request->validate([
            'title'           => 'sometimes|string|max:255',
            'description'     => 'nullable|string',
            'category'        => 'sometimes|string|in:Hotel,Apartment,Kosan,Guest House,Villa,Resort',
            'listing_type'    => 'nullable|string|in:sell,rent',
            'price'           => 'sometimes|integer|min:1',
            'price_negotiable'=> 'nullable',
            'address'         => 'nullable|string',
            'city'            => 'sometimes|string|max:100',
            'province'        => 'nullable|string|max:100',
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
            'land_area'       => 'nullable|integer|min:1',
            'building_area'   => 'nullable|integer|min:1',
            'bedrooms'        => 'nullable|integer|min:0|max:255',
            'bathrooms'       => 'nullable|integer|min:0|max:255',
            'certificate'     => 'nullable|string|in:SHM,HGB,Strata,Lainnya',
            'facilities'      => 'nullable|array',
            'contact_phone'   => 'nullable|string|max:30',
            'contact_email'   => 'nullable|email|max:100',
            'images.*'        => 'nullable|image|mimes:jpg,jpeg|max:5120|dimensions:min_width=800,min_height=800',
        ], \App\Http\Controllers\InteriorDesignController::uploadMessages(800));

        if ($request->has('price_negotiable')) {
            $data['price_negotiable'] = filter_var($request->input('price_negotiable'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->hasFile('images') || $request->has('existing_images')) {
            $existing = $request->input('existing_images', []);
            if (is_string($existing)) {
                $existing = json_decode($existing, true) ?? [];
            }
            $data['images'] = $this->applyPrimaryImage(
                $this->uploadImages($request, (array) $existing),
                $request->input('primary_index')
            );
        }

        // Reset to pending after owner edit so admin reviews again
        if ($isOwner) {
            $data['status']           = 'pending';
            $data['rejection_reason'] = null;
        }

        $listing->update($data);

        return response()->json(['success' => true, 'message' => 'Listing berhasil diperbarui.', 'data' => $listing]);
    }

    // ── Owner: delete own listing ─────────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $user    = auth('sanctum')->user();
        $listing = PropertyListing::findOrFail($id);

        $isSuperadmin = $user->hasRole('superadmin');
        $isOwner      = $user->hasRole('owner') && (int) $listing->owner_id === (int) $user->id;

        if (!$isSuperadmin && !$isOwner) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $listing->delete();

        return response()->json(['success' => true, 'message' => 'Listing berhasil dihapus.']);
    }

    // ── Owner: own listings with status ──────────────────────────────────
    public function myListings(Request $request)
    {
        $user = auth('sanctum')->user();

        $listings = PropertyListing::where('owner_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $listings]);
    }

    // ── Superadmin: list pending listings ─────────────────────────────────
    public function pending(Request $request)
    {
        $user  = $request->user();
        $query = PropertyListing::pending()
            ->with('owner:id,name,email,phone')
            ->orderByDesc('created_at');

        // Market Manager: filter to assigned owners only
        if ($user->hasRole('admin')) {
            $ownerIds = \App\Models\MarketManagerOwner::where('market_manager_id', $user->id)->pluck('owner_id');
            if ($ownerIds->isEmpty()) {
                return response()->json([
                    'success'    => true,
                    'data'       => [],
                    'pagination' => ['total' => 0, 'totalPages' => 1, 'currentPage' => 1],
                ]);
            }
            $query->whereIn('owner_id', $ownerIds);
        }

        $listings = $query->paginate($request->limit ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $listings->items(),
            'pagination' => [
                'total'       => $listings->total(),
                'totalPages'  => $listings->lastPage(),
                'currentPage' => $listings->currentPage(),
            ],
        ]);
    }

    // ── Superadmin: approve listing, notify owner ─────────────────────────
    public function approve(Request $request, string $id)
    {
        $user    = auth('sanctum')->user();
        $listing = PropertyListing::findOrFail($id);

        $listing->update([
            'status'          => 'approved',
            'approved_by'     => $user->id,
            'approved_at'     => now(),
            'rejection_reason'=> null,
        ]);

        NotificationService::send(
            $listing->owner_id, 'property_listing_approved',
            'Listing Properti Disetujui',
            "Listing \"{$listing->title}\" Anda telah disetujui dan sekarang aktif.",
            ['listing_id' => $listing->id, 'listing_title' => $listing->title]
        );

        return response()->json(['success' => true, 'message' => 'Listing properti disetujui.', 'data' => $listing]);
    }

    // ── Superadmin: reject with reason, notify owner ──────────────────────
    public function reject(Request $request, string $id)
    {
        $listing = PropertyListing::findOrFail($id);

        $data = $request->validate([
            'reason' => 'required|string|min:5',
        ]);

        $listing->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['reason'],
        ]);

        NotificationService::send(
            $listing->owner_id, 'property_listing_rejected',
            'Listing Properti Ditolak',
            "Listing \"{$listing->title}\" Anda ditolak. Alasan: {$data['reason']}",
            ['listing_id' => $listing->id, 'listing_title' => $listing->title, 'reason' => $data['reason']]
        );

        return response()->json(['success' => true, 'message' => 'Listing properti ditolak.', 'data' => $listing]);
    }

    /**
     * Pindahkan foto pada $index ke posisi pertama → jadi thumbnail kartu (images[0]).
     * $index = posisi foto utama di array gabungan (existing dipertahankan + upload baru).
     */
    private function applyPrimaryImage(array $images, $index): array
    {
        if ($index === null || $index === '') return $images;
        $i = (int) $index;
        if ($i <= 0 || !isset($images[$i])) return $images;
        $primary = $images[$i];
        unset($images[$i]);
        array_unshift($images, $primary);
        return array_values($images);
    }

    // ── Private: upload images helper ─────────────────────────────────────
    private function uploadImages(Request $request, array $existing): array
    {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $dir     = storage_path('app/public/uploads/properties');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $urls = $existing;

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                if (count($urls) >= 10) break;
                $ext = strtolower($file->getClientOriginalExtension());
                if (!in_array($ext, $allowed)) continue;
                if ($file->getSize() > 10 * 1024 * 1024) continue;
                $filename = 'property_' . time() . '_' . uniqid() . '.' . $ext;
                $oldUmask = umask(0022);
                $file->move($dir, $filename);
                umask($oldUmask);
                @chmod($dir . DIRECTORY_SEPARATOR . $filename, 0644);
                $urls[] = 'uploads/properties/' . $filename;
            }
        }

        return $urls;
    }
}
