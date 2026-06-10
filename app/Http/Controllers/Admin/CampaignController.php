<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class CampaignController extends Controller
{
    // Admin: semua campaign (global) + jumlah owner yang mengikuti
    public function index(Request $request)
    {
        $campaigns = Campaign::withCount('followers')->latest()->get();
        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Public: campaigns aktif untuk hotel ini = campaign yang DIIKUTI ownernya ──
    public function forHotel(string $hotelId)
    {
        $hotel = \App\Models\Hotel::findOrFail($hotelId);

        $campaigns = Campaign::where('status', 'active')
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->whereHas('followers', fn($q) => $q->where('users.id', $hotel->owner_id))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Public: campaign aktif untuk ditampilkan di home website ──────────
    // Semua campaign dibuat superadmin = global. Tampilkan yang status='active'
    // & belum expired (termasuk upcoming).
    public function activePublic()
    {
        $campaigns = Campaign::where('status', 'active')
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Owner: SEMUA campaign aktif (global) + flag followed untuk owner ini ──
    public function myList(Request $request)
    {
        $userId = $request->user()->id;
        $campaigns = Campaign::where('status', 'active')
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->withCount(['followers as is_followed' => fn($q) => $q->where('users.id', $userId)])
            ->latest()
            ->get()
            ->map(function ($c) {
                $c->followed = (bool) $c->is_followed;
                unset($c->is_followed);
                return $c;
            });
        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Owner: ikut / berhenti ikut campaign ─────────────────────────────
    public function follow(Request $request, string $id)
    {
        $campaign = Campaign::where('status', 'active')->findOrFail($id);
        $campaign->followers()->syncWithoutDetaching([$request->user()->id]);

        return response()->json([
            'success'  => true,
            'message'  => 'Campaign diikuti. Akan tampil di halaman properti Anda.',
            'followed' => true,
        ]);
    }

    public function unfollow(Request $request, string $id)
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->followers()->detach($request->user()->id);

        return response()->json([
            'success'  => true,
            'message'  => 'Campaign dihentikan.',
            'followed' => false,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'type'             => 'required|array|min:1',
            'type.*'           => 'in:banner,popup',
            'target'           => 'required|in:all,new_user,loyal,inactive',
            'status'           => 'required|in:draft,active,inactive,ended',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'description'      => 'nullable|string',
            'image'            => 'nullable|file|max:4096',
            'banner'           => 'required|file|max:6144',   // banner landscape WAJIB
        ]);

        $this->assertValidImage($request, 'image');
        $this->assertValidImage($request, 'banner');

        // type bisa multi (banner/popup) → simpan sebagai "banner,popup"
        $data['type']             = implode(',', $data['type']);
        // Semua campaign = global (dibuat superadmin, masuk ke semua owner).
        $data['owner_id']         = null;
        $data['created_by']       = $request->user()->id;
        $data['discount_percent'] = $data['discount_percent'] ?? 0;

        if ($request->hasFile('image')) {
            $data['image'] = $this->storeImageLocally($request->file('image'));
        }
        if ($request->hasFile('banner')) {
            $data['banner'] = $this->storeImageLocally($request->file('banner'));
        }

        $campaign = Campaign::create($data);
        return response()->json(['success' => true, 'data' => $campaign], 201);
    }

    public function update(Request $request, string $id)
    {
        $campaign = Campaign::findOrFail($id);

        $data = $request->validate([
            'title'            => 'sometimes|required|string|max:255',
            'type'             => 'sometimes|required|array|min:1',
            'type.*'           => 'in:banner,popup',
            'target'           => 'sometimes|required|in:all,new_user,loyal,inactive',
            'status'           => 'sometimes|required|in:draft,active,inactive,ended',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'description'      => 'nullable|string',
            'image'            => 'nullable|file|max:4096',
            'banner'           => 'nullable|file|max:6144',
        ]);

        $this->assertValidImage($request, 'image');
        $this->assertValidImage($request, 'banner');

        if (isset($data['type'])) {
            $data['type'] = implode(',', $data['type']);
        }

        if ($request->hasFile('image')) {
            if ($campaign->image) {
                $oldPath = storage_path('app/public/' . $campaign->image);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $data['image'] = $this->storeImageLocally($request->file('image'));
        }
        if ($request->hasFile('banner')) {
            if ($campaign->banner) {
                $oldPath = storage_path('app/public/' . $campaign->banner);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $data['banner'] = $this->storeImageLocally($request->file('banner'));
        }

        $campaign->update($data);
        return response()->json(['success' => true, 'data' => $campaign]);
    }

    public function destroy(string $id)
    {
        Campaign::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Campaign dihapus.']);
    }

    // ── Validasi ekstensi image manual (server tanpa ext fileinfo) ────────
    private function assertValidImage(Request $request, string $field = 'image'): void
    {
        if ($request->hasFile($field)) {
            $ext = strtolower($request->file($field)->getClientOriginalExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Format gambar harus jpg, jpeg, png, atau webp.',
                ], 422));
            }
        }
    }

    // ── Simpan image tanpa Flysystem (sama seperti PromoController) ────────
    private function storeImageLocally(UploadedFile $file): string
    {
        $dir  = storage_path('app/public/uploads/campaigns');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $name = uniqid('campaign_', true) . '.' . $ext;
        $oldMask = umask(0022);
        $file->move($dir, $name);
        umask($oldMask);
        @chmod($dir . DIRECTORY_SEPARATOR . $name, 0644);
        return 'uploads/campaigns/' . $name;
    }
}
