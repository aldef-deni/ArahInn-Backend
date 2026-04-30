<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    private function safe(): array
    {
        return ['id', 'name', 'email', 'phone', 'avatar', 'is_active', 'created_at'];
    }

    public function profile(Request $request)
    {
        $user = $request->user()->load('roles');
        return response()->json(['success' => true, 'data' => array_merge($user->only($this->safe()), ['role' => $user->getRoleNames()->first()])]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string',
            'phone' => 'nullable|string',
        ]);
        $request->user()->update($data);

        return response()->json(['success' => true, 'data' => $request->user()->fresh()]);
    }

    public function updateAvatar(Request $request)
    {
        $request->validate(['avatar' => 'required|max:10240']);

        $user = $request->user();
        $file = $request->file('avatar');

        $ext      = strtolower($file->getClientOriginalExtension());
        $allowed  = ['jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Format file tidak didukung.'], 422);
        }

        // Hapus avatar lama jika tersimpan lokal
        if ($user->avatar && str_starts_with($user->avatar, '/uploads/')) {
            $oldPath = public_path($user->avatar);
            if (file_exists($oldPath)) @unlink($oldPath);
        }

        $dir = public_path('uploads/avatars');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = 'avatar_' . $user->id . '_' . time() . '.' . $ext;
        $file->move($dir, $filename);

        $url = url('uploads/avatars/' . $filename);
        $user->update(['avatar' => $url]);

        return response()->json(['success' => true, 'data' => ['avatar' => $url]]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:8',
        ]);

        if (!Hash::check($data['old_password'], $request->user()->password)) {
            return response()->json(['success' => false, 'message' => 'Password lama salah.'], 400);
        }

        $request->user()->update(['password' => Hash::make($data['new_password'])]);

        return response()->json(['success' => true, 'message' => 'Password berhasil diubah.']);
    }

    public function index(Request $request)
    {
        $pengelolaRoles = ['superadmin', 'admin', 'owner', 'finance'];

        $query = User::with('roles');

        if ($search = $request->search) {
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        if ($role = $request->role) {
            $query->role($role);
        } elseif ($group = $request->group) {
            if ($group === 'pengelola') {
                $query->role($pengelolaRoles);
            } elseif ($group === 'pengguna') {
                $query->role('user');
            }
        }

        $result = $query->latest()->paginate($request->limit ?? 20);
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

    public function show(string $id)
    {
        $user = User::with('roles')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $user]);
    }
}
