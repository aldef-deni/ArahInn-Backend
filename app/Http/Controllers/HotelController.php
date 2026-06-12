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
    /**
     * Effective display price untuk satu kamar pada tanggal tertentu.
     * Cek room_prices override dulu, fallback ke base_price default.
     */
    private function effectivePriceForRoom($room, string $dateStr): float
    {
        $override = \App\Models\RoomPrice::where('room_id', $room->id)
            ->whereDate('date', $dateStr)
            ->whereNotNull('price')
            ->value('price');
        return (float) ($override ?? $room->base_price);
    }

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

        // Tanggal untuk harga effective: kalau user pilih check_in, pakai itu; default = hari ini
        $priceDate = $request->input('check_in') ?: now()->format('Y-m-d');

        // Tempel info promo platform yang di-follow owner ke kamar termurah tiap hotel
        // → memungkinkan FE menampilkan harga coret + harga promo di card list
        $items = collect($result->items())->map(function ($hotel) use ($priceDate) {
            $arr = $hotel->toArray();
            $rooms = $hotel->rooms ?? collect();

            // Override base_price tiap kamar pakai harga effective (per-date override kalau ada)
            if ($rooms->isNotEmpty() && !empty($arr['rooms'])) {
                foreach ($arr['rooms'] as $i => &$roomArr) {
                    $room = $rooms[$i] ?? null;
                    if ($room) {
                        $roomArr['base_price'] = $this->effectivePriceForRoom($room, $priceDate);
                    }
                }
                unset($roomArr);
            }

            if ($hotel->owner_id && $rooms->isNotEmpty()) {
                $cheapest = $rooms->first();
                $effective = $this->effectivePriceForRoom($cheapest, $priceDate);
                $best = \App\Services\OwnerDiscountService::best($hotel->owner_id, $effective);
                if ($best) {
                    $arr['min_price']         = $effective;
                    $arr['discounted_price']  = $best['final'];
                    $arr['discount_amount']   = $best['discount'];
                    $arr['discount_source']   = $best['source'];
                    $arr['applied_promo']     = $best['applied'];
                }
            }
            return $arr;
        });

        return response()->json([
            'success' => true,
            'data' => $items,
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
        // Accept either numeric ID or slug
        $query = Hotel::with(['owner:id,name,email,phone', 'rooms' => fn($q) => $q->active()]);
        $hotel = is_numeric($id)
            ? $query->findOrFail((int) $id)
            : $query->where('slug', $id)->firstOrFail();

        // Date untuk harga effective (room_prices override). Default hari ini.
        $priceDate = request()->input('check_in') ?: now()->format('Y-m-d');

        // Tempel info promo platform yang diikuti owner ke tiap kamar
        // + override base_price pakai harga effective (per-date override kalau ada)
        if ($hotel->rooms) {
            $rooms = $hotel->rooms->map(function ($room) use ($hotel, $priceDate) {
                $effective = $this->effectivePriceForRoom($room, $priceDate);
                $arr = $room->toArray();
                // Override base_price supaya FE langsung baca harga aktual yang di-set owner
                $arr['base_price']    = $effective;
                $arr['default_price'] = (float) $room->base_price;  // simpan default original untuk referensi

                if ($hotel->owner_id) {
                    $best = \App\Services\OwnerDiscountService::best($hotel->owner_id, $effective);
                    if ($best) {
                        $arr['applied_promo']    = $best['applied'];
                        $arr['discount_source']  = $best['source'];
                        $arr['original_price']   = $effective;
                        $arr['discounted_price'] = $best['final'];
                        $arr['discount_amount']  = $best['discount'];
                    }
                }
                return $arr;
            });
            $hotel->setRelation('rooms', collect($rooms));
        }

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
            'name'     => 'required|string|max:255',
            'address'  => 'required|string',
            'city'     => 'required|string',
            'owner_id' => 'nullable|integer|exists:users,id',
        ]);

        $user = $request->user();

        // Tentukan owner: kalau admin/superadmin kirim owner_id → pakai itu.
        // Selain itu (owner login sendiri) → user id sendiri.
        $isAdmin   = $user->hasRole('superadmin') || $user->hasRole('admin');
        $ownerId   = ($isAdmin && $request->filled('owner_id'))
            ? (int) $request->input('owner_id')
            : $user->id;

        // Admin wajib pilih owner saat create lewat form lengkap
        if ($isAdmin && !$request->filled('owner_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Pemilik (Owner) wajib dipilih sebelum hotel baru disimpan.',
                'errors'  => ['owner_id' => ['Pemilik (Owner) wajib dipilih.']],
            ], 422);
        }

        $bool = fn($v) => ($v === null || $v === '')
            ? null
            : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $upload = function ($file, string $dir): ?string {
            if (!$file || !$file->isValid()) return null;
            $ext = strtolower($file->getClientOriginalExtension());
            // Image: jpg/jpeg only. PDF tetap diizinkan untuk dokumen (NPWP, dll)
            $isImageDir = str_starts_with($dir, 'uploads/hotels')
                       || str_starts_with($dir, 'uploads/rooms');
            $allowed = $isImageDir ? ['jpg','jpeg'] : ['jpg','jpeg','pdf'];
            if (!in_array($ext, $allowed)) return null;
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
        $uploadWarnings = [];
        $hotelImages = [];
        foreach ($request->file('hotel_photos', []) as $gi => $files) {
            $cat = $request->input("photo_categories.{$gi}", '');
            foreach ((array) $files as $file) {
                if ($file && !$file->isValid()) { $uploadWarnings[] = $this->uploadErrorMessage($file); continue; }
                $path = $upload($file, 'uploads/hotels');
                if ($path) $hotelImages[] = ['path' => $path, 'category' => $cat];
                elseif ($file) $uploadWarnings[] = ($file->getClientOriginalName() ?: 'Foto') . ': format tidak didukung (harus JPG/JPEG).';
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
            'owner_id'            => $ownerId,
            'slug'                => Hotel::generateUniqueSlug($request->input('name')),
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
            'voucher_emails'      => $this->sanitizeVoucherEmails($jsonDecode('voucher_emails')),
            'platforms'           => $jsonDecode('platforms'),
            'facilities'          => $jsonDecode('facilities'),
            'images'              => $hotelImages,
            'gender_policy'       => $bool($request->input('gender_policy')),
            'marriage_book'       => $bool($request->input('marriage_book')),
            'deposit_required'    => $bool($request->input('deposit_required')),
            'all_ages_allowed'    => $bool($request->input('all_ages_allowed')),
            'min_age'             => $request->filled('min_age') ? (int) $request->input('min_age') : null,
            // Info Check-In (Step 2)
            'booking_min_age'     => $request->filled('booking_min_age') ? (int) $request->input('booking_min_age') : null,
            'check_in_24h'        => $bool($request->input('check_in_24h')) ?? false,
            'check_in_start'      => $request->input('check_in_start')  ?: null,
            'check_in_end'        => $request->input('check_in_end')    ?: null,
            'check_out_start'     => $request->input('check_out_start') ?: null,
            'check_out_end'       => $request->input('check_out_end')   ?: null,
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
                    if ($file && !$file->isValid()) { $uploadWarnings[] = $this->uploadErrorMessage($file); continue; }
                    $path = $upload($file, 'uploads/rooms');
                    if ($path) $roomImages[] = ['path' => $path, 'category' => $cat];
                    elseif ($file) $uploadWarnings[] = ($file->getClientOriginalName() ?: 'Foto kamar') . ': format tidak didukung (harus JPG/JPEG).';
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

        // Kirim email partnership ke owner sebenarnya (bukan admin yang membuat)
        try {
            $ownerUser = \App\Models\User::find($ownerId);
            if ($ownerUser?->email) {
                Mail::to($ownerUser->email)->send(new PartnershipAgreementMail($hotel, $ownerUser));
            }
        } catch (\Throwable) {}

        // Notif ke owner kalau admin yang buat (supaya owner tahu hotel-nya didaftarkan)
        if ($isAdmin && $ownerId !== $user->id) {
            NotificationService::send(
                $ownerId, 'hotel_created_by_admin',
                'Hotel Baru Didaftarkan oleh Admin',
                "Hotel \"{$hotel->name}\" telah didaftarkan oleh admin dan menunggu persetujuan.",
                ['hotel_id' => $hotel->id, 'hotel_name' => $hotel->name]
            );
        }

        ActivityLogService::log($user->id, $isAdmin ? 'ADMIN_CREATE_HOTEL_FULL' : 'CREATE_HOTEL', 'hotel', $hotel->id, $request);

        NotificationService::sendToRoles(['superadmin', 'admin'], 'hotel_new',
            'Hotel Baru Menunggu Persetujuan',
            "Hotel \"{$hotel->name}\" didaftarkan dan menunggu persetujuan.",
            ['hotel_id' => $hotel->id, 'hotel_name' => $hotel->name]
        );

        $message = 'Hotel berhasil didaftarkan, menunggu persetujuan admin.';
        if (!empty($uploadWarnings)) {
            $message = 'Hotel terdaftar, tetapi ' . count($uploadWarnings) . ' foto GAGAL diupload — lihat detail.';
        }

        return response()->json([
            'success'  => true,
            'message'  => $message,
            'warnings' => $uploadWarnings,
            'data'     => $hotel->load('rooms'),
        ], 201);
    }

    /**
     * Bersihkan & validasi array email tambahan untuk voucher.
     * Filter: trim, lowercase, hapus duplikat & yang format invalid.
     * Return null kalau kosong supaya kolom DB null (bukan []).
     */
    private function sanitizeVoucherEmails($input): ?array
    {
        if (!is_array($input)) return null;
        $clean = collect($input)
            ->map(fn($e) => is_string($e) ? strtolower(trim($e)) : null)
            ->filter(fn($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
        return empty($clean) ? null : $clean;
    }

    public function update(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        $user  = $request->user();

        $isSuperadmin = $user->hasRole('superadmin');
        $isAdmin      = $user->hasRole('admin');
        $isHotelOwner = $user->hasRole('owner') && (int) $hotel->owner_id === (int) $user->id;

        if (!$isSuperadmin && !$isAdmin && !$isHotelOwner) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        // Apakah ini full-form update (dari halaman DaftarHotel) atau partial update biasa?
        $isFullForm = $request->has('rooms_meta')
                   || $request->has('photo_categories')
                   || $request->has('guest_types')
                   || $request->has('platforms');

        if ($isFullForm) {
            return $this->updateFullForm($request, $hotel);
        }

        // ── Partial update (drawer admin / patch ringan) ──
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'category'    => 'nullable|string|in:Hotel,Apartment,Kosan,Guest House,Villa,Resort,Glamping',
            'description' => 'nullable|string',
            'address'     => 'sometimes|string',
            'city'        => 'sometimes|string',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'facilities'  => 'nullable|array',
            'images.*'    => 'nullable|image|mimes:jpg,jpeg|max:5120|dimensions:min_width=800,min_height=800',
            'booking_min_age' => 'nullable|integer|min:0|max:99',
            'check_in_24h'    => 'nullable|boolean',
            'check_in_start'  => 'nullable|date_format:H:i',
            'check_in_end'    => 'nullable|date_format:H:i',
            'check_out_start' => 'nullable|date_format:H:i',
            'check_out_end'   => 'nullable|date_format:H:i',
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

    /**
     * Update penuh dari multi-step form (DaftarHotel edit mode oleh superadmin/admin/owner).
     * Menerima semua field yang sama seperti store(), plus existing_photos & rooms_meta dengan id.
     */
    private function updateFullForm(Request $request, Hotel $hotel)
    {
        $user = $request->user();

        $bool = fn($v) => ($v === null || $v === '')
            ? null
            : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $upload = function ($file, string $dir): ?string {
            if (!$file || !$file->isValid()) return null;
            $ext = strtolower($file->getClientOriginalExtension());
            $isImageDir = str_starts_with($dir, 'uploads/hotels')
                       || str_starts_with($dir, 'uploads/rooms');
            $allowed = $isImageDir ? ['jpg','jpeg'] : ['jpg','jpeg','pdf'];
            if (!in_array($ext, $allowed)) return null;
            $fullDir = storage_path("app/public/{$dir}");
            if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);
            $filename = uniqid('f_', true) . '.' . $ext;
            $file->move($fullDir, $filename);
            return "{$dir}/{$filename}";
        };

        $jsonDecode = fn($key, $default = []) => is_array($request->input($key))
            ? $request->input($key)
            : (json_decode($request->input($key, json_encode($default)), true) ?? $default);

        // ── Foto hotel: existing yang dipertahankan + upload baru ──
        $uploadWarnings = [];
        $existingImages = $jsonDecode('existing_images', []);
        $hotelImages    = [];
        foreach ((array) $existingImages as $img) {
            if (!is_array($img) || empty($img['path'])) continue;
            $hotelImages[] = [
                'path'     => $img['path'],
                'category' => $img['category'] ?? '',
            ];
        }
        foreach ($request->file('hotel_photos', []) as $gi => $files) {
            $cat = $request->input("photo_categories.{$gi}", '');
            foreach ((array) $files as $file) {
                if ($file && !$file->isValid()) { $uploadWarnings[] = $this->uploadErrorMessage($file); continue; }
                $path = $upload($file, 'uploads/hotels');
                if ($path) $hotelImages[] = ['path' => $path, 'category' => $cat];
                elseif ($file) $uploadWarnings[] = ($file->getClientOriginalName() ?: 'Foto') . ': format tidak didukung (harus JPG/JPEG).';
            }
        }

        // ── Docs ──
        $npwpDoc     = $request->file('npwp_doc')         ? $upload($request->file('npwp_doc'),         'uploads/docs') : null;
        $nituDoc     = $request->file('nitku_doc')        ? $upload($request->file('nitku_doc'),        'uploads/docs') : null;
        $npwpSupport = $request->file('npwp_support_doc') ? $upload($request->file('npwp_support_doc'), 'uploads/docs') : null;

        // ── Breakfast time ──
        $bfStart = $request->filled('breakfast_start_hour')
            ? $request->input('breakfast_start_hour').':'.($request->input('breakfast_start_minute','00'))
            : $request->input('breakfast_start');
        $bfEnd   = $request->filled('breakfast_end_hour')
            ? $request->input('breakfast_end_hour').':'.($request->input('breakfast_end_minute','00'))
            : $request->input('breakfast_end');

        // ── Slug: regenerate hanya jika nama berubah ──
        $newName = $request->input('name', $hotel->name);
        $slug    = $hotel->slug;
        if ($newName !== $hotel->name) {
            $slug = Hotel::generateUniqueSlug($newName, $hotel->id);
        }

        $payload = [
            'slug'                => $slug,
            'name'                => $newName,
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
            'voucher_emails'      => $this->sanitizeVoucherEmails($jsonDecode('voucher_emails')),
            'platforms'           => $jsonDecode('platforms', (object) []),
            'facilities'          => $jsonDecode('facilities'),
            'images'              => $hotelImages,
            'gender_policy'       => $bool($request->input('gender_policy')),
            'marriage_book'       => $bool($request->input('marriage_book')),
            'deposit_required'    => $bool($request->input('deposit_required')),
            'all_ages_allowed'    => $bool($request->input('all_ages_allowed')),
            'min_age'             => $request->filled('min_age') ? (int) $request->input('min_age') : null,
            'booking_min_age'     => $request->filled('booking_min_age') ? (int) $request->input('booking_min_age') : null,
            'check_in_24h'        => $bool($request->input('check_in_24h')) ?? false,
            'check_in_start'      => $request->input('check_in_start')  ?: null,
            'check_in_end'        => $request->input('check_in_end')    ?: null,
            'check_out_start'     => $request->input('check_out_start') ?: null,
            'check_out_end'       => $request->input('check_out_end')   ?: null,
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
            'nitku_number'        => $request->input('nitku_number'),
            'nitku_name'          => $request->input('nitku_name'),
            'registration_source' => $request->input('registration_source', $hotel->registration_source),
        ];

        // Hanya overwrite doc jika ada upload baru
        if ($npwpDoc)     $payload['npwp_doc']         = $npwpDoc;
        if ($nituDoc)     $payload['nitku_doc']        = $nituDoc;
        if ($npwpSupport) $payload['npwp_support_doc'] = $npwpSupport;

        $hotel->update($payload);

        // ── Rooms: update existing by id, create new, hapus yang tidak ada di payload ──
        $roomsMeta = json_decode($request->input('rooms_meta', '[]'), true) ?? [];
        $keepRoomIds = [];

        foreach ($roomsMeta as $ri => $rd) {
            // Existing photos untuk room ini
            $existingRoomPhotos = [];
            $erpKey = "existing_room_photos.{$ri}";
            $erpRaw = $request->input($erpKey, []);
            if (is_string($erpRaw)) {
                $erpRaw = json_decode($erpRaw, true) ?? [];
            }
            foreach ((array) $erpRaw as $img) {
                if (!is_array($img) || empty($img['path'])) continue;
                $existingRoomPhotos[] = [
                    'path'     => $img['path'],
                    'category' => $img['category'] ?? '',
                ];
            }

            // Upload room photos baru
            $newRoomPhotos = [];
            foreach ($request->file("room_photos.{$ri}", []) as $gi => $files) {
                $cat = $request->input("room_photos_category.{$ri}.{$gi}", '');
                foreach ((array) $files as $file) {
                    if ($file && !$file->isValid()) { $uploadWarnings[] = $this->uploadErrorMessage($file); continue; }
                    $path = $upload($file, 'uploads/rooms');
                    if ($path) $newRoomPhotos[] = ['path' => $path, 'category' => $cat];
                    elseif ($file) $uploadWarnings[] = ($file->getClientOriginalName() ?: 'Foto kamar') . ': format tidak didukung (harus JPG/JPEG).';
                }
            }

            $roomImages = array_merge($existingRoomPhotos, $newRoomPhotos);

            $roomPayload = [
                'name'           => $rd['room_name'] ?? '',
                'type'           => $rd['room_type'] ?? 'standard',
                'max_guests'     => (int) ($rd['max_occupancy'] ?? 2),
                'base_price'     => (float) ($rd['price_threshold'] ?? 0),
                'facilities'     => $rd['room_facilities'] ?? [],
                'smoking_policy' => isset($rd['smoking_policy']) && $rd['smoking_policy'] !== ''
                    ? filter_var($rd['smoking_policy'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
                'has_bedrooms'   => isset($rd['has_bedrooms']) && $rd['has_bedrooms'] !== ''
                    ? filter_var($rd['has_bedrooms'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
                'bed_configs'    => $rd['bed_configs'] ?? [],
                'images'         => $roomImages,
            ];

            $existingId = isset($rd['id']) ? (int) $rd['id'] : 0;
            if ($existingId > 0) {
                $room = $hotel->rooms()->where('id', $existingId)->first();
                if ($room) {
                    $room->update($roomPayload);
                    $keepRoomIds[] = $room->id;
                    continue;
                }
            }
            $newRoom = $hotel->rooms()->create($roomPayload);
            $keepRoomIds[] = $newRoom->id;
        }

        // Hapus kamar yang tidak dikirim ulang (kecuali admin/owner sengaja kosongkan)
        if (!empty($roomsMeta)) {
            $hotel->rooms()->whereNotIn('id', $keepRoomIds)->delete();
        }

        ActivityLogService::log($user->id, 'UPDATE_HOTEL_FULL', 'hotel', $hotel->id, $request);

        $message = 'Hotel berhasil diperbarui.';
        if (!empty($uploadWarnings)) {
            $message = 'Hotel diperbarui, tetapi ' . count($uploadWarnings) . ' foto GAGAL diupload — lihat detail.';
        }

        return response()->json([
            'success'  => true,
            'message'  => $message,
            'warnings' => $uploadWarnings,
            'data'     => $hotel->fresh()->load('rooms'),
        ]);
    }

    /** Pesan error upload yang jelas untuk ditampilkan ke user. */
    private function uploadErrorMessage($file): string
    {
        $name  = method_exists($file, 'getClientOriginalName') ? ($file->getClientOriginalName() ?: 'Foto') : 'Foto';
        $limit = ini_get('upload_max_filesize');
        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                "{$name}: ukuran melebihi batas server (maks {$limit}). Kompres foto agar lebih kecil, atau naikkan limit upload server.",
            UPLOAD_ERR_PARTIAL  => "{$name}: upload terputus, coba ulangi.",
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => "{$name}: server gagal menyimpan file, hubungi admin.",
            default => "{$name}: gagal diupload (kode error {$file->getError()}).",
        };
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
