<?php

namespace App\Http\Controllers;

use App\Models\InteriorDesign;
use Illuminate\Http\Request;

class InteriorDesignController extends Controller
{
    private const MAX_VIDEO_MB = 20;

    // Public — hanya yang approved
    public function publicIndex()
    {
        return response()->json([
            'data' => InteriorDesign::where('status', 'approved')->latest()->get(),
        ]);
    }

    // Admin/superadmin — semua; owner & design_interior — hanya miliknya
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = InteriorDesign::with('owner:id,name')->latest();

        if ($user->hasRole('owner') || $user->hasRole('design_interior')) {
            $query->where('owner_id', $user->id);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $maxVideoKb = self::MAX_VIDEO_MB * 1024;

        $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'wa_number'   => 'nullable|string|max:30',
            'images'      => 'required|array|min:1',
            'images.*'    => 'required|file|mimes:jpg,jpeg|max:5120|dimensions:min_width=600,min_height=600',
            'videos'      => 'nullable|array',
            'videos.*'    => "nullable|file|mimes:mpg,avi,mp4|max:{$maxVideoKb}",
        ]);

        $data             = $request->only(['title', 'description', 'wa_number']);
        $data['status']   = 'pending';
        $data['owner_id'] = $request->user()->id;
        $data['images']   = [];
        $data['videos']   = [];

        foreach ($request->file('images', []) as $file) {
            $data['images'][] = $this->saveFile($file, 'interior/images');
        }
        foreach ($request->file('videos', []) as $file) {
            $data['videos'][] = $this->saveFile($file, 'interior/videos');
        }

        $design = InteriorDesign::create($data);

        return response()->json([
            'message' => 'Desain berhasil ditambahkan dan menunggu persetujuan.',
            'data'    => $design,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $design     = InteriorDesign::findOrFail($id);
        $maxVideoKb = self::MAX_VIDEO_MB * 1024;

        $user = $request->user();
        $isRestricted = $user->hasRole('owner') || $user->hasRole('design_interior');
        if ($isRestricted && $design->owner_id != null && (int)$design->owner_id !== (int)$user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($design->owner_id == null && $isRestricted) {
            $design->owner_id = $user->id;
        }

        $request->validate([
            'title'           => 'sometimes|required|string|max:255',
            'style'           => 'nullable|string|max:100',
            'description'     => 'nullable|string',
            'wa_number'       => 'nullable|string|max:30',
            'images'          => 'nullable|array',
            'images.*'        => 'required|file|mimes:jpg,jpeg|max:5120|dimensions:min_width=600,min_height=600',
            'videos'          => 'nullable|array',
            'videos.*'        => "nullable|file|mimes:mpg,avi,mp4|max:{$maxVideoKb}",
            'remove_images'   => 'nullable|array',
            'remove_images.*' => 'string',
            'remove_videos'   => 'nullable|array',
            'remove_videos.*' => 'string',
        ]);

        $data = array_filter($request->only(['title', 'description', 'wa_number']), fn($v) => $v !== null);

        $currentImages = $design->images ?? [];
        $currentVideos = $design->videos ?? [];

        foreach ($request->input('remove_images', []) as $path) {
            $this->deleteFile($path);
            $currentImages = array_values(array_filter($currentImages, fn($p) => $p !== $path));
        }
        foreach ($request->input('remove_videos', []) as $path) {
            $this->deleteFile($path);
            $currentVideos = array_values(array_filter($currentVideos, fn($p) => $p !== $path));
        }
        foreach ($request->file('images', []) as $file) {
            $currentImages[] = $this->saveFile($file, 'interior/images');
        }
        foreach ($request->file('videos', []) as $file) {
            $currentVideos[] = $this->saveFile($file, 'interior/videos');
        }

        $data['images'] = array_values($currentImages);
        $data['videos'] = array_values($currentVideos);

        $design->update($data);

        return response()->json(['message' => 'Desain berhasil diperbarui.', 'data' => $design]);
    }

    public function approve(Request $request, $id)
    {
        $design = InteriorDesign::findOrFail($id);
        $design->update(['status' => 'approved']);

        return response()->json(['message' => 'Desain disetujui.', 'data' => $design]);
    }

    public function reject(Request $request, $id)
    {
        $design = InteriorDesign::findOrFail($id);
        $design->update(['status' => 'rejected']);

        return response()->json(['message' => 'Desain ditolak.', 'data' => $design]);
    }

    public function destroy($id)
    {
        $design = InteriorDesign::findOrFail($id);

        foreach ($design->images ?? [] as $path) {
            $this->deleteFile($path);
        }
        foreach ($design->videos ?? [] as $path) {
            $this->deleteFile($path);
        }

        $design->delete();

        return response()->json(['message' => 'Desain berhasil dihapus.']);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function saveFile($file, string $subDir): string
    {
        $dir = storage_path("app/public/uploads/{$subDir}");
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext      = strtolower($file->getClientOriginalExtension());
        $filename = uniqid() . '_' . time() . '.' . $ext;

        $oldUmask = umask(0022);
        $file->move($dir, $filename);
        umask($oldUmask);
        @chmod($dir . DIRECTORY_SEPARATOR . $filename, 0644);

        return "storage/uploads/{$subDir}/{$filename}";
    }

    private function deleteFile(?string $path): void
    {
        if (!$path) return;
        $relative = preg_replace('#^storage/#', '', $path);
        $fullPath = storage_path("app/public/{$relative}");
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
}
