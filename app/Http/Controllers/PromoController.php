<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PromoController extends Controller
{
    // ── Public: active global promos + owner-targeted promos for authenticated owner ──
    public function active(Request $request)
    {
        // Untuk DISPLAY di home: tampilkan promo yang aktif DAN yang akan datang
        // (upcoming, start_date di masa depan), asalkan belum expired. Beda dengan
        // scope active() yang dipakai untuk PENERAPAN diskon (butuh start_date <= now).
        $query = Promo::where('is_active', true)
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->orderByRaw('CASE WHEN start_date IS NULL OR start_date <= NOW() THEN 0 ELSE 1 END') // yang berjalan dulu
            ->orderBy('start_date');
        $user  = auth('sanctum')->user();

        if ($user && $user->hasRole('owner')) {
            $userId = $user->id;
            $query->where(function ($q) use ($userId) {
                $q->whereNull('owner_id')
                  ->orWhere(function ($q2) use ($userId) {
                      $q2->where('owner_id', $userId)
                         ->where('created_by', '!=', $userId);
                  });
            });
        } else {
            // Publik/customer: tampilkan promo PLATFORM (owner_id null) ATAU promo
            // yang DIBUAT oleh admin/superadmin (meski ditargetkan ke owner tertentu).
            // Aturan: promo buatan superadmin wajib tampil di home.
            $adminIds = \App\Models\User::role(['admin', 'superadmin'])->pluck('id');
            $query->where(function ($q) use ($adminIds) {
                $q->whereNull('owner_id')
                  ->orWhereIn('created_by', $adminIds);
            });
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function flashSales()
    {
        $promos = Promo::active()->whereNull('owner_id')->where('type', 'flash_sale')->get();
        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Public: Flyer cards untuk main website ─────────────────────────────
    // Tampilkan semua promo dari ArahInn (owner_id null) yang punya image,
    // termasuk yang belum mulai (start_date di masa depan).
    // Hanya filter: is_active = true & belum expired (end_date >= now atau null).
    public function flyers()
    {
        $promos = Promo::whereNull('owner_id')
            ->whereNotNull('image')
            ->where('is_active', true)
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Public: voucher owner aktif untuk sebuah properti (tampil di detail hotel) ──
    public function hotelPromos($hotelId)
    {
        $hotel = \App\Models\Hotel::find($hotelId);
        if (!$hotel || !$hotel->owner_id) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $promos = Promo::active()
            ->where('owner_id', $hotel->owner_id)
            ->where(fn($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $hotel->id))
            ->where(fn($q) => $q->whereNull('quota')->orWhereColumn('used_count', '<', 'quota'))
            ->orderByDesc('created_at')
            ->get(['id', 'code', 'name', 'description', 'discount_type', 'discount_value',
                   'min_purchase', 'max_discount', 'end_date', 'quota', 'used_count']);

        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Public: validate promo code at checkout ───────────────────────────
    public function validate(Request $request)
    {
        $data = $request->validate([
            'code'      => 'required|string',
            'amount'    => 'required|numeric',
            'hotel_id'  => 'nullable|integer',
            'check_in'  => 'nullable|date',
            'stay_type' => 'nullable|string|in:daily,weekly,monthly',
        ]);

        $promo = Promo::where('code', $data['code'])->active()->first();

        if (!$promo) {
            return response()->json(['success' => false, 'message' => 'Kode promo tidak valid.'], 400);
        }

        $hotel = isset($data['hotel_id']) ? Hotel::find($data['hotel_id']) : null;

        // Check owner scope (cast int — hindari mismatch tipe string vs int)
        if ($promo->owner_id !== null && $hotel && (int) $hotel->owner_id !== (int) $promo->owner_id) {
            return response()->json(['success' => false, 'message' => 'Kode promo tidak berlaku untuk hotel ini.'], 400);
        }

        // Check hotel scope (promo yang dibatasi ke 1 properti)
        if ($promo->hotel_id !== null && $hotel && (int) $promo->hotel_id !== (int) $hotel->id) {
            return response()->json(['success' => false, 'message' => 'Kode promo hanya berlaku untuk properti tertentu.'], 400);
        }

        // Kondisi opsional (weekday/weekend, jenis akomodasi, lokasi, tipe menginap)
        if ($err = $promo->conditionError($hotel, $data['check_in'] ?? null, 'accommodation', $data['stay_type'] ?? 'daily')) {
            return response()->json(['success' => false, 'message' => $err], 400);
        }

        if ($promo->quota && $promo->used_count >= $promo->quota) {
            return response()->json(['success' => false, 'message' => 'Kuota promo habis.'], 400);
        }
        if ($data['amount'] < $promo->min_purchase) {
            return response()->json(['success' => false, 'message' => 'Minimum pembelian tidak terpenuhi.'], 400);
        }

        $discount = $promo->calculateDiscount($data['amount']);
        return response()->json(['success' => true, 'data' => ['promo' => $promo, 'discount' => round($discount, 2)]]);
    }

    // ── Admin: list all promos with owner info ────────────────────────────
    public function index()
    {
        // Hanya promo PLATFORM (dibuat ArahInn, owner_id null). Promo milik owner
        // akomodasi TIDAK ditampilkan di menu superadmin — owner kelola sendiri
        // lewat extranet masing-masing.
        $promos = Promo::whereNull('owner_id')->with('owner:id,name,email')->latest()->get();
        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Owner: list own promos (created by owner themselves, not admin-targeted) ──
    public function myPromos(Request $request)
    {
        $userId = $request->user()->id;
        $promos = Promo::where('owner_id', $userId)
                       ->where('created_by', $userId)
                       ->latest()->get();
        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Owner: follow / unfollow platform promo ──────────────────────────
    // Setelah follow, promo akan otomatis berlaku untuk semua hotel milik owner ini
    // — harga coret + harga diskon ditampilkan di detail hotel & checkout.
    public function follow(Request $request, string $id)
    {
        $user = $request->user();
        $promo = Promo::whereNull('owner_id')->findOrFail($id);

        $promo->followers()->syncWithoutDetaching([$user->id]);

        return response()->json([
            'success'  => true,
            'message'  => 'Promo diikuti. Diskon akan otomatis berlaku untuk hotel Anda.',
            'followed' => true,
        ]);
    }

    public function unfollow(Request $request, string $id)
    {
        $user = $request->user();
        $promo = Promo::whereNull('owner_id')->findOrFail($id);

        $promo->followers()->detach($user->id);

        return response()->json([
            'success'  => true,
            'message'  => 'Promo dihentikan.',
            'followed' => false,
        ]);
    }

    // ── Owner: list platform promos (global + admin-targeted to this owner) ──
    // Tidak filter start_date supaya promo yang akan datang tetap tampil di extranet owner.
    public function platformPromos(Request $request)
    {
        $userId = $request->user()->id;
        $promos = Promo::where('is_active', true)
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->where(function ($q) use ($userId) {
                $q->whereNull('owner_id')
                  ->orWhere(function ($q2) use ($userId) {
                      $q2->where('owner_id', $userId)
                         ->where('created_by', '!=', $userId);
                  });
            })
            ->withCount(['followers as is_followed' => fn ($q) => $q->where('users.id', $userId)])
            ->latest()
            ->get()
            ->map(function ($p) {
                $p->followed = (bool) $p->is_followed;
                unset($p->is_followed);
                return $p;
            });

        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Admin: list owners for dropdown ──────────────────────────────────
    public function ownersList()
    {
        $owners = User::role('owner')->select('id', 'name', 'email')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $owners]);
    }

    // ── Admin + Owner: create promo ───────────────────────────────────────
    public function store(Request $request)
    {
        $user  = $request->user();
        $isAdmin = $user->hasAnyRole(['admin', 'superadmin']);

        $data = $request->validate([
            'name'           => 'required|string',
            'code'           => 'required|string|unique:promos',  // voucher WAJIB pakai kode
            'description'    => 'nullable|string|max:2000',
            'image'          => 'nullable|file|max:5120|dimensions:min_width=1536,min_height=1024', // maks 5 MB, resolusi min 1536×1024
            'discount_type'  => 'required|in:percent,fixed',
            'discount_value' => 'required|numeric|min:0',
            'min_purchase'   => 'nullable|numeric',
            'max_discount'   => 'nullable|numeric',
            'quota'          => 'nullable|integer',
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date|after:start_date',
            'owner_id'       => 'nullable|integer|exists:users,id',
            // Kondisi opsional
            'day_type'        => 'nullable|in:weekday,weekend',
            'hotel_types'     => 'nullable|array',
            'hotel_types.*'   => 'string|max:50',
            'location'        => 'nullable|string|max:255',
            'product_types'   => 'nullable|array',
            'product_types.*' => 'in:accommodation,pesawat,pelni,kereta',
            'stay_types'      => 'nullable|array',                 // [] / null = harian saja
            'stay_types.*'    => 'in:daily,weekly,monthly',
            'hotel_id'        => 'nullable|integer|exists:hotels,id', // null = semua properti
        ], [
            'image.max'        => 'Ukuran gambar maksimal 5 MB.',
            'image.dimensions' => 'Resolusi gambar minimal 1536×1024 piksel.',
        ]);

        // Semua promo bertipe voucher (berlaku via kode di checkout).
        $data['type'] = 'voucher';

        // Manual extension check (bypass fileinfo)
        if ($request->hasFile('image')) {
            $ext = strtolower($request->file('image')->getClientOriginalExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                return response()->json(['success' => false, 'message' => 'Format gambar harus jpg, jpeg, png, atau webp.'], 422);
            }
        }

        $data['min_purchase'] = $data['min_purchase'] ?? 0;
        $data['created_by']   = $user->id;

        if ($isAdmin) {
            $data['owner_id'] = $data['owner_id'] ?? null;
        } else {
            $data['owner_id'] = $user->id;
        }

        // hotel_id (opsional): null = semua properti. Kalau diisi owner, wajib
        // properti miliknya sendiri.
        if (!empty($data['hotel_id']) && !$isAdmin) {
            $ownsHotel = \App\Models\Hotel::where('id', $data['hotel_id'])
                ->where('owner_id', $user->id)->exists();
            if (!$ownsHotel) {
                return response()->json(['success' => false, 'message' => 'Properti tidak valid / bukan milik Anda.'], 422);
            }
        }

        // Upload image flyer kalau ada (bypass Flysystem — server tanpa ext fileinfo)
        if ($request->hasFile('image')) {
            $data['image'] = $this->storeImageLocally($request->file('image'));
        }

        $promo = Promo::create($data);
        return response()->json(['success' => true, 'data' => $promo->load('owner:id,name,email')], 201);
    }

    // ── Admin + Owner: update promo ───────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $promo = Promo::findOrFail($id);
        $user  = $request->user();

        if (!$user->hasAnyRole(['admin', 'superadmin']) && $promo->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
        }

        $allowed = ['name', 'description', 'discount_type', 'discount_value', 'min_purchase',
                    'max_discount', 'quota', 'start_date', 'end_date', 'is_active',
                    'day_type', 'hotel_types', 'location', 'product_types', 'stay_types'];

        if ($user->hasAnyRole(['admin', 'superadmin'])) {
            $allowed[] = 'owner_id';
        }

        $updates = $request->only($allowed);

        // Normalisasi kondisi kosong → null (tidak diterapkan)
        if (array_key_exists('day_type', $updates) && !$updates['day_type']) $updates['day_type'] = null;
        if (array_key_exists('location', $updates) && !$updates['location']) $updates['location'] = null;
        if (array_key_exists('hotel_types', $updates) && empty($updates['hotel_types'])) $updates['hotel_types'] = null;
        if (array_key_exists('product_types', $updates) && empty($updates['product_types'])) $updates['product_types'] = null;
        if (array_key_exists('stay_types', $updates) && empty($updates['stay_types'])) $updates['stay_types'] = null;

        // Upload image flyer kalau ada file baru (bypass Flysystem)
        if ($request->hasFile('image')) {
            $request->validate(['image' => 'file|max:5120|dimensions:min_width=1536,min_height=1024'], [
                'image.max'        => 'Ukuran gambar maksimal 5 MB.',
                'image.dimensions' => 'Resolusi gambar minimal 1536×1024 piksel.',
            ]); // maks 5 MB, resolusi min 1536×1024
            $ext = strtolower($request->file('image')->getClientOriginalExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                return response()->json(['success' => false, 'message' => 'Format gambar harus jpg, jpeg, png, atau webp.'], 422);
            }
            // Hapus image lama langsung dari filesystem
            if ($promo->image) {
                $oldPath = storage_path('app/public/' . $promo->image);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $updates['image'] = $this->storeImageLocally($request->file('image'));
        }

        $promo->update($updates);
        return response()->json(['success' => true, 'data' => $promo->load('owner:id,name,email')]);
    }

    // ── Helper: simpan image tanpa Flysystem (untuk server tanpa ext fileinfo) ──
    // Pakai folder uploads/promos supaya URL-nya disajikan via mekanisme yang sama
    // dengan upload hotel/room (sudah jalan di server).
    private function storeImageLocally(UploadedFile $file): string
    {
        $dir     = storage_path('app/public/uploads/promos');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext     = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $name    = uniqid('promo_', true) . '.' . $ext;
        $oldMask = umask(0022);
        $file->move($dir, $name);
        umask($oldMask);
        @chmod($dir . DIRECTORY_SEPARATOR . $name, 0644);
        return 'uploads/promos/' . $name;
    }

    // ── Admin + Owner: delete promo ───────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $promo = Promo::findOrFail($id);
        $user  = $request->user();

        if (!$user->hasAnyRole(['admin', 'superadmin']) && $promo->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
        }

        $promo->delete();
        return response()->json(['success' => true, 'message' => 'Promo dihapus.']);
    }
}
