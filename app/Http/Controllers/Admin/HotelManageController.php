<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\MarketManagerOwner;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HotelManageController extends Controller
{
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = Hotel::with('owner:id,name,email');

        // Market Manager: filter to assigned owners only
        if ($user->hasRole('admin')) {
            $ownerIds = MarketManagerOwner::where('market_manager_id', $user->id)->pluck('owner_id');
            if ($ownerIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => [], 'pagination' => ['total' => 0, 'totalPages' => 1, 'page' => 1]]);
            }
            $query->whereIn('owner_id', $ownerIds);
        }

        if ($search = $request->search) {
            $query->where(fn($q) => $q
                ->where('name',    'like', "%{$search}%")
                ->orWhere('city',  'like', "%{$search}%")
                ->orWhere('address','like', "%{$search}%")
            );
        }
        if ($status = $request->status) {
            $query->where('status', $status);
        }

        $result = $query->orderBy('created_at', 'desc')->paginate($request->limit ?? 15);

        return response()->json([
            'success'    => true,
            'data'       => $result->items(),
            'pagination' => [
                'total'      => $result->total(),
                'totalPages' => $result->lastPage(),
                'page'       => $result->currentPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'address'     => 'required|string',
            'city'        => 'required|string',
            'province'    => 'nullable|string',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'facilities'  => 'nullable|array',
            'status'      => 'nullable|in:pending,approved,blocked',
            'owner_id'    => 'required|exists:users,id',
        ], [
            'owner_id.required' => 'Pemilik (Owner) wajib dipilih sebelum hotel baru disimpan.',
            'owner_id.exists'   => 'Pemilik (Owner) yang dipilih tidak valid.',
        ]);

        $data['slug']   = Hotel::generateUniqueSlug($data['name']);
        $data['status'] = $data['status'] ?? 'approved';

        if ($data['status'] === 'approved') {
            $data['approved_by'] = $request->user()->id;
            $data['approved_at'] = now();
        }

        $data['images'] = $this->uploadImages($request, []);
        $data['voucher_emails'] = $this->parseVoucherEmails($request);

        $hotel = Hotel::create($data);
        ActivityLogService::log($request->user()->id, 'ADMIN_CREATE_HOTEL', 'hotel', $hotel->id, $request);

        return response()->json([
            'success' => true,
            'message' => 'Hotel berhasil ditambahkan.',
            'data'    => $hotel->load('owner:id,name,email'),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'address'     => 'sometimes|string',
            'city'        => 'sometimes|string',
            'province'    => 'nullable|string',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'facilities'  => 'nullable|array',
            'status'      => 'nullable|in:pending,approved,blocked',
            'owner_id'    => 'nullable|exists:users,id',
        ]);

        if (isset($data['status']) && $data['status'] === 'approved' && $hotel->status !== 'approved') {
            $data['approved_by'] = $request->user()->id;
            $data['approved_at'] = now();
        }

        // Merge kept existing images + newly uploaded images
        $existingImages = $request->input('existing_images', []);
        if (is_string($existingImages)) {
            $existingImages = json_decode($existingImages, true) ?? [];
        }
        $data['images'] = $this->uploadImages($request, (array) $existingImages);

        // Email penerima voucher (editable langsung dari drawer admin). Hanya
        // diubah bila dikirim, agar update lain tak menghapusnya.
        if ($request->has('voucher_emails')) {
            $data['voucher_emails'] = $this->parseVoucherEmails($request);
        }

        $hotel->update($data);
        ActivityLogService::log($request->user()->id, 'ADMIN_UPDATE_HOTEL', 'hotel', $id, $request);

        return response()->json([
            'success' => true,
            'data'    => $hotel->fresh(['owner:id,name,email']),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        ActivityLogService::log($request->user()->id, 'ADMIN_DELETE_HOTEL', 'hotel', $id, $request);
        $hotel->delete();

        return response()->json(['success' => true, 'message' => 'Hotel berhasil dihapus.']);
    }

    /**
     * Update komisi platform untuk sebuah hotel.
     * Markup final di PricingService = commission_percent + 2% bonus.
     */
    public function updateCommission(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);

        $data = $request->validate([
            'commission_percent' => 'required|numeric|min:0|max:100',
        ]);

        $hotel->update($data);
        ActivityLogService::log(
            $request->user()->id,
            'ADMIN_UPDATE_COMMISSION',
            'hotel',
            $id,
            $request
        );

        return response()->json([
            'success' => true,
            'message' => "Komisi {$hotel->name} berhasil disetel ke {$hotel->commission_percent}% (markup final " . ($hotel->commission_percent + 2) . "%).",
            'data'    => [
                'id'                 => $hotel->id,
                'name'               => $hotel->name,
                'commission_percent' => (float) $hotel->commission_percent,
                'markup_percent'     => (float) $hotel->commission_percent + 2,
            ],
        ]);
    }

    public function pending()
    {
        $hotels = Hotel::where('status', 'pending')
            ->with('owner:id,name,email,phone')
            ->orderBy('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $hotels]);
    }

    public function approve(Request $request, string $id)
    {
        $hotel = Hotel::findOrFail($id);
        $hotel->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        ActivityLogService::log($request->user()->id, 'APPROVE_HOTEL', 'hotel', $id, $request);

        NotificationService::send(
            $hotel->owner_id, 'hotel_approved',
            'Hotel Disetujui! 🎉',
            "Hotel \"{$hotel->name}\" Anda telah disetujui dan sekarang aktif di platform.",
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
            "Hotel \"{$hotel->name}\" Anda telah diblokir oleh admin. Hubungi kami untuk informasi lebih lanjut.",
            ['hotel_id' => $hotel->id, 'hotel_name' => $hotel->name]
        );

        return response()->json(['success' => true, 'message' => 'Hotel diblokir.']);
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

    /**
     * Bersihkan daftar email penerima voucher dari request (JSON string, CSV, atau array).
     * Lowercase, validasi format, unik. Kosong → null.
     */
    private function parseVoucherEmails(Request $request): ?array
    {
        $ve = $request->input('voucher_emails');
        if (is_string($ve)) {
            $decoded = json_decode($ve, true);
            $ve = is_array($decoded)
                ? $decoded
                : array_filter(array_map('trim', explode(',', $ve)));
        }
        if (!is_array($ve)) return null;

        $clean = collect($ve)
            ->map(fn ($e) => is_string($e) ? strtolower(trim($e)) : null)
            ->filter(fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        return empty($clean) ? null : $clean;
    }
}
