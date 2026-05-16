<?php

namespace App\Http\Controllers;

use App\Mail\PartnershipAgreementMail;
use App\Models\Hotel;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class HotelController extends Controller
{
    public function search(Request $request)
    {
        $query = Hotel::approved()
            ->with(['rooms' => fn($q) => $q->active()->orderBy('base_price')->limit(1)])
            ->withCount('bookings');

        if ($q = $request->q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%")
                    ->orWhere('category', 'like', "%{$q}%");
            });
        } elseif ($city = $request->city) {
            $query->where('city', 'like', "%{$city}%");
        }
        if ($name = $request->name) {
            $query->where('name', 'like', "%{$name}%");
        }
        if ($categories = $request->categories) {
            $cats = is_array($categories) ? $categories : explode(',', $categories);
            $query->whereIn('category', array_map('trim', $cats));
        } elseif ($category = $request->category) {
            $query->where('category', $category);
        }
        if ($starRatings = $request->star_ratings) {
            $stars = is_array($starRatings) ? $starRatings : explode(',', $starRatings);
            $query->whereIn('star_rating', array_map('intval', $stars));
        } elseif ($star = $request->star_rating) {
            $query->where('star_rating', $star);
        }
        if ($minPrice = $request->min_price) {
            $query->whereHas('rooms', fn($q) => $q->where('base_price', '>=', $minPrice)->where('is_active', true));
        }
        if ($maxPrice = $request->max_price) {
            $query->whereHas('rooms', fn($q) => $q->where('base_price', '<=', $maxPrice)->where('is_active', true));
        }
        if ($facilities = $request->facilities) {
            $list = is_array($facilities) ? $facilities : explode(',', $facilities);
            foreach ($list as $facility) {
                $query->whereJsonContains('facilities', $facility);
            }
        }
        if ($sortBy = $request->sort_by) {
            match ($sortBy) {
                'price_asc'  => $query->withMin('rooms', 'base_price')->orderBy('rooms_min_base_price'),
                'price_desc' => $query->withMin('rooms', 'base_price')->orderByDesc('rooms_min_base_price'),
                'rating'     => $query->orderByDesc('star_rating'),
                default      => $query->orderByDesc('bookings_count'),
            };
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
        $request->validate([
            'name'    => 'required|string|max:255',
            'address' => 'required|string',
            'city'    => 'required|string',
        ]);

        $user = $request->user();

        $bool = fn($v) => ($v === null || $v === '')
            ? null
            : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $upload = function ($file, string $dir): ?string {
            if (!$file || !$file->isValid()) return null;
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, ['jpg','jpeg','png','webp','pdf'])) return null;
            $fullDir = storage_path("app/public/{$dir}");
            if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);
            $filename = uniqid('f_', true) . '.' . $ext;
            $file->move($fullDir, $filename);
            return "{$dir}/{$filename}";
        };

        $jsonDecode = fn($key) => is_array($request->input($key))
            ? $request->input($key)
            : (json_decode($request->input($key, 'null'), true) ?? []);

        // Hotel photos
        $hotelImages = [];
        foreach ($request->file('hotel_photos', []) as $gi => $files) {
            $cat = $request->input("photo_categories.{$gi}", '');
            foreach ((array) $files as $file) {
                $path = $upload($file, 'uploads/hotels');
                if ($path) $hotelImages[] = ['path' => $path, 'category' => $cat];
            }
        }

        // Docs
        $npwpDoc     = $upload($request->file('npwp_doc'),         'uploads/docs');
        $nituDoc     = $upload($request->file('nitku_doc'),        'uploads/docs');
        $npwpSupport = $upload($request->file('npwp_support_doc'), 'uploads/docs');

        // Breakfast time
        $bfStart = $request->filled('breakfast_start_hour')
            ? $request->input('breakfast_start_hour').':'.($request->input('breakfast_start_minute','00'))
            : $request->input('breakfast_start');
        $bfEnd   = $request->filled('breakfast_end_hour')
            ? $request->input('breakfast_end_hour').':'.($request->input('breakfast_end_minute','00'))
            : $request->input('breakfast_end');

        $hotel = Hotel::create([
            'owner_id'            => $user->id,
            'slug'                => Str::slug($request->input('name')).'-'.time(),
            'status'              => 'pending',
            'name'                => $request->input('name'),
            'alias'               => $request->input('alias'),
            'category'            => $request->input('category'),
            'description'         => $request->input('description'),
            'is_brand_chain'      => $bool($request->input('is_brand_chain')) ?? false,
            'currency'            => $request->input('currency', 'IDR'),
            'star_rating'         => $request->input('star_rating'),
            'address'             => $request->input('address'),
            'city'                => $request->input('city'),
            'district'            => $request->input('district'),
            'village'             => $request->input('village'),
            'province'            => $request->input('province'),
            'country'             => $request->input('country', 'Indonesia'),
            'postal_code'         => $request->input('postal_code'),
            'latitude'            => $request->input('latitude') ?: null,
            'longitude'           => $request->input('longitude') ?: null,
            'guest_types'         => $jsonDecode('guest_types'),
            'pic_position'        => $request->input('position'),
            'pic_phone'           => $request->input('phone'),
            'property_phone'      => $request->input('property_phone'),
            'fax'                 => $request->input('fax'),
            'company_name'        => $request->input('company_name'),
            'company_address'     => $request->input('company_address'),
            'company_country'     => $request->input('company_country', 'Indonesia'),
            'agree_name'          => $request->input('agree_name'),
            'agree_position'      => $request->input('agree_position'),
            'agree_email'         => $request->input('agree_email'),
            'agree_phone'         => $request->input('agree_phone'),
            'platforms'           => $jsonDecode('platforms'),
            'facilities'          => $jsonDecode('facilities'),
            'images'              => $hotelImages,
            'gender_policy'       => $bool($request->input('gender_policy')),
            'marriage_book'       => $bool($request->input('marriage_book')),
            'deposit_required'    => $bool($request->input('deposit_required')),
            'all_ages_allowed'    => $bool($request->input('all_ages_allowed')),
            'min_age'             => $request->filled('min_age') ? (int) $request->input('min_age') : null,
            'breakfast_available' => $bool($request->input('breakfast_available')),
            'breakfast_start'     => $bfStart,
            'breakfast_end'       => $bfEnd,
            'smoking_allowed'     => $bool($request->input('smoking_allowed')),
            'alcohol_allowed'     => $bool($request->input('alcohol_allowed')),
            'pets_allowed'        => $bool($request->input('pets_allowed')),
            'cancellation_policy' => $request->input('cancellation_policy'),
            'payment_method'      => $request->input('payment_method'),
            'bank_name'           => $request->input('bank_name'),
            'bank_branch'         => $request->input('bank_branch'),
            'bank_account_name'   => $request->input('bank_account_name'),
            'bank_account_number' => $request->input('bank_account_number'),
            'vcc_accepted_types'  => $jsonDecode('vcc_accepted_types'),
            'vcc_email'           => $request->input('vcc_email'),
            'vcc_account_name'    => $request->input('vcc_account_name'),
            'npwp_type'           => $request->input('npwp_type'),
            'npwp_number'         => $request->input('npwp_number'),
            'npwp_name'           => $request->input('npwp_name'),
            'npwp_doc'            => $npwpDoc,
            'nitku_number'        => $request->input('nitku_number'),
            'nitku_name'          => $request->input('nitku_name'),
            'nitku_doc'           => $nituDoc,
            'npwp_support_doc'    => $npwpSupport,
            'registration_source' => $request->input('registration_source'),
        ]);

        // Rooms
        $roomsMeta = json_decode($request->input('rooms_meta', '[]'), true) ?? [];
        foreach ($roomsMeta as $ri => $rd) {
            $roomImages = [];
            foreach ($request->file("room_photos.{$ri}", []) as $gi => $files) {
                $cat = $request->input("room_photos_category.{$ri}.{$gi}", '');
                foreach ((array) $files as $file) {
                    $path = $upload($file, 'uploads/rooms');
                    if ($path) $roomImages[] = ['path' => $path, 'category' => $cat];
                }
            }
            $hotel->rooms()->create([
                'name'           => $rd['room_name'] ?? '',
                'type'           => $rd['room_type'] ?? 'standard',
                'max_guests'     => (int) ($rd['max_occupancy'] ?? 2),
                'base_price'     => (float) ($rd['price_threshold'] ?? 0),
                'facilities'     => $rd['room_facilities'] ?? [],
                'images'         => $roomImages,
                'smoking_policy' => isset($rd['smoking_policy']) && $rd['smoking_policy'] !== ''
                    ? filter_var($rd['smoking_policy'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
                'has_bedrooms'   => isset($rd['has_bedrooms']) && $rd['has_bedrooms'] !== ''
                    ? filter_var($rd['has_bedrooms'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
                'bed_configs'    => $rd['bed_configs'] ?? [],
            ]);
        }

        try {
            Mail::to($user->email)->send(new PartnershipAgreementMail($hotel, $user));
        } catch (\Throwable) {}

        ActivityLogService::log($user->id, 'CREATE_HOTEL', 'hotel', $hotel->id, $request);

        NotificationService::sendToRoles(['superadmin', 'admin'], 'hotel_new',
            'Hotel Baru Menunggu Persetujuan',
            "Hotel \"{$hotel->name}\" didaftarkan dan menunggu persetujuan.",
            ['hotel_id' => $hotel->id, 'hotel_name' => $hotel->name]
        );

        return response()->json([
            'success' => true,
            'message' => 'Hotel berhasil didaftarkan, menunggu persetujuan admin.',
            'data'    => $hotel->load('rooms'),
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
            'name'        => 'sometimes|string|max:255',
            'category'    => 'nullable|string|in:Hotel,Apartment,Kosan,Guest House,Villa,Resort,Glamping',
            'description' => 'nullable|string',
            'address'     => 'sometimes|string',
            'city'        => 'sometimes|string',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'facilities'  => 'nullable|array',
            'images.*'    => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:min_width=1024,min_height=1024',
        ]);

        if ($request->hasFile('images') || $request->has('existing_images')) {
            $existing = $request->input('existing_images', []);
            if (is_string($existing)) {
                $existing = json_decode($existing, true) ?? [];
            }
            $data['images'] = $this->uploadImages($request, (array) $existing);
        }

        $hotel->update($data);

        return response()->json(['success' => true, 'data' => $hotel]);
    }

    public function approve(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        $hotel->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        ActivityLogService::log($request->user()->id, 'APPROVE_HOTEL', 'hotel', $id, $request);

        NotificationService::send(
            $hotel->owner_id, 'hotel_approved',
            'Hotel Disetujui',
            "Hotel \"{$hotel->name}\" Anda telah disetujui dan sekarang aktif.",
            ['hotel_id' => $hotel->id, 'hotel_name' => $hotel->name]
        );

        return response()->json(['success' => true, 'message' => 'Hotel disetujui.', 'data' => $hotel]);
    }

    public function block(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        $hotel->update(['status' => 'blocked']);
        ActivityLogService::log($request->user()->id, 'BLOCK_HOTEL', 'hotel', $id, $request);

        NotificationService::send(
            $hotel->owner_id, 'hotel_blocked',
            'Hotel Diblokir',
            "Hotel \"{$hotel->name}\" Anda telah diblokir oleh admin.",
            ['hotel_id' => $hotel->id, 'hotel_name' => $hotel->name]
        );

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

    public function myHotels(Request $request)
    {
        $hotels = Hotel::where('owner_id', $request->user()->id)
            ->with(['rooms' => fn($q) => $q->active()])
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $hotels]);
    }

    private function uploadImages(Request $request, array $existing): array
    {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $dir     = storage_path('app/public/uploads/hotels');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $urls = $existing;

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                if (count($urls) >= 10) break;
                $ext = strtolower($file->getClientOriginalExtension());
                if (!in_array($ext, $allowed)) continue;
                if ($file->getSize() > 10 * 1024 * 1024) continue;
                $filename = 'hotel_' . time() . '_' . uniqid() . '.' . $ext;
                $oldUmask = umask(0022);
                $file->move($dir, $filename);
                umask($oldUmask);
                @chmod($dir . DIRECTORY_SEPARATOR . $filename, 0644);
                $urls[] = 'uploads/hotels/' . $filename;
            }
        }

        return $urls;
    }
}
