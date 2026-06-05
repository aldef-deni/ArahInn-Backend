<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserManageController extends Controller
{
    private const ROLES = ['superadmin','owner','admin_property','admin','finance','design_interior','user'];

    public function store(Request $request)
    {
        // Model AKUN TERPISAH: email sama boleh punya beberapa akun dgn role berbeda
        // (mis. budi@mail.com bisa jadi customer DAN owner sebagai 2 akun terpisah).
        // Yang dicegah hanya duplikat email untuk role yang SAMA.
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|email',
            'password'  => 'required|min:6',
            'phone'     => 'nullable|string',
            'role'      => 'required|in:' . implode(',', self::ROLES),
            'is_active' => 'boolean',
        ]);

        $primaryRole = $data['role'];

        // Cegah 2 akun dengan email + role yang sama persis.
        $dup = User::where('email', $data['email'])->where('primary_role', $primaryRole)->first();
        if ($dup) {
            return response()->json([
                'success' => false,
                'message' => "Sudah ada akun dengan email ini untuk role {$primaryRole}.",
                'errors'  => ['email' => ["Email sudah dipakai untuk akun {$primaryRole}."]],
            ], 422);
        }

        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'password'     => Hash::make($data['password']),
            'phone'        => $data['phone'] ?? null,
            'is_active'    => $data['is_active'] ?? true,
            'primary_role' => $primaryRole,
        ]);

        $user->assignRole($data['role']);
        ActivityLogService::log($request->user()->id, 'CREATE_USER', 'user', $user->id, $request);

        return response()->json([
            'success' => true,
            'message' => 'Akun ' . $primaryRole . ' berhasil dibuat.',
            'data'    => $user->load('roles'),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'email'     => 'sometimes|email|unique:users,email,' . $id,
            'password'  => 'nullable|min:6',
            'phone'     => 'nullable|string',
            'role'      => 'sometimes|in:' . implode(',', self::ROLES),
            'is_active' => 'boolean',
        ]);

        $update = array_filter([
            'name'      => $data['name']      ?? null,
            'email'     => $data['email']     ?? null,
            'phone'     => $data['phone']     ?? null,
            'is_active' => isset($data['is_active']) ? $data['is_active'] : null,
        ], fn($v) => $v !== null);

        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        if (!empty($update)) {
            $user->update($update);
        }

        if (!empty($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        ActivityLogService::log($request->user()->id, 'UPDATE_USER', 'user', $id, $request);

        return response()->json([
            'success' => true,
            'message' => 'Pengguna berhasil diperbarui.',
            'data'    => $user->fresh('roles'),
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        if ((int)$id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat menghapus akun Anda sendiri.',
            ], 400);
        }

        $user = User::findOrFail($id);
        ActivityLogService::log($request->user()->id, 'DELETE_USER', 'user', $id, $request);
        $user->delete();

        return response()->json(['success' => true, 'message' => 'Pengguna berhasil dihapus.']);
    }

    public function changeRole(Request $request, string $id)
    {
        $data = $request->validate([
            'role' => 'required|in:' . implode(',', self::ROLES),
        ]);

        $user = User::findOrFail($id);
        $user->syncRoles([$data['role']]);
        ActivityLogService::log($request->user()->id, 'CHANGE_ROLE', 'user', $id, $request);

        return response()->json([
            'success' => true,
            'data'    => $user->fresh('roles'),
            'message' => 'Role berhasil diubah.',
        ]);
    }

    public function toggleStatus(Request $request, string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['is_active' => !$user->is_active]);

        return response()->json([
            'success' => true,
            'message' => $user->is_active ? 'User diaktifkan.' : 'User dinonaktifkan.',
            'data'    => $user,
        ]);
    }
}
