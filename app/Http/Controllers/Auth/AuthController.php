<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    // ── Register Customer ─────────────────────────────
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|min:3|max:255',
            'email'    => 'required|email',
            'password' => ['required', Password::min(6)],
            'phone'    => 'nullable|string|max:20',
        ]);

        $result = $this->upsertUserWithRole($data, 'user', $request);
        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status']);
        }

        $user  = $result['user']; $isNew = $result['isNew'];
        $token = $user->createToken('auth-token')->plainTextToken;

        if ($isNew) {
            try { Mail::to($user->email)->queue(new \App\Mail\WelcomeMail($user)); } catch (\Throwable) {}
            ActivityLogService::log($user->id, 'REGISTER', 'user', $user->id, $request);
        } else {
            ActivityLogService::log($user->id, 'ADD_ROLE_CUSTOMER', 'user', $user->id, $request);
        }

        return response()->json([
            'success' => true,
            'message' => $isNew
                ? 'Registrasi berhasil.'
                : 'Akun customer berhasil ditambahkan ke email yang sudah ada.',
            'data'    => ['user' => $this->userResource($user), 'token' => $token],
        ], 201);
    }

    // ── Register Owner (self-service via extranet) ────
    public function registerOwner(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|min:3|max:255',
            'email'    => 'required|email',
            // Password minimal 8 char + huruf + angka (cocok dengan strength meter di FE)
            'password' => ['required', Password::min(8)->letters()->numbers()],
            'phone'    => 'nullable|string|max:20',
        ]);

        $result = $this->upsertUserWithRole($data + ['is_active' => true], 'owner', $request);
        if (isset($result['error'])) {
            return response()->json($result['error'], $result['status']);
        }

        $user  = $result['user']; $isNew = $result['isNew'];
        $token = $user->createToken('auth-token')->plainTextToken;

        if ($isNew) {
            try { Mail::to($user->email)->queue(new \App\Mail\WelcomeMail($user)); } catch (\Throwable) {}
        }
        ActivityLogService::log($user->id, $isNew ? 'REGISTER_OWNER' : 'ADD_ROLE_OWNER', 'user', $user->id, $request);

        // Notif ke superadmin & admin
        try {
            \App\Services\NotificationService::sendToRoles(
                ['superadmin', 'admin'],
                'owner_registered',
                $isNew ? 'Owner baru terdaftar' : 'User existing menambah role owner',
                "{$user->name} ({$user->email}) " . ($isNew ? 'mendaftar sebagai owner' : 'menambah role owner pada akun customer-nya') . " via Extranet.",
                ['user_id' => $user->id, 'user_name' => $user->name, 'user_email' => $user->email]
            );
        } catch (\Throwable) {}

        return response()->json([
            'success' => true,
            'message' => $isNew
                ? 'Registrasi owner berhasil. Silakan lengkapi properti Anda.'
                : 'Role owner berhasil ditambahkan ke akun Anda. Silakan lengkapi properti.',
            'data'    => ['user' => $this->userResource($user), 'token' => $token],
        ], 201);
    }

    /**
     * Helper: buat user baru ATAU tambah role ke user existing.
     *
     * Logika:
     *  - Email belum ada → buat user baru, assign role yang diminta.
     *  - Email sudah ada + password cocok → tambah role baru (kalau belum punya).
     *  - Email sudah ada + password salah → return error (anti claim email orang lain).
     *
     * Return shape:
     *   ['user' => User, 'isNew' => bool] (sukses)
     *   ['error' => array, 'status' => int]  (gagal)
     */
    private function upsertUserWithRole(array $data, string $role, Request $request): array
    {
        $existing = User::where('email', $data['email'])->first();

        // Email belum terdaftar → user baru
        if (!$existing) {
            $user = User::create($data);
            $user->assignRole($role);
            return ['user' => $user, 'isNew' => true];
        }

        // Email sudah ada → verifikasi password supaya hanya pemilik akun
        // yang bisa menambah role baru. Mencegah orang lain "claim" email.
        if (!\Hash::check($data['password'], $existing->password)) {
            return [
                'error' => [
                    'success' => false,
                    'error'   => 'email_exists_password_mismatch',
                    'message' => 'Email ini sudah terdaftar di Arahinn. Untuk menambahkan role baru, gunakan password yang sama dengan akun Anda. Lupa password? Reset dulu via halaman login.',
                ],
                'status' => 422,
            ];
        }

        // Cek apakah role sudah dimiliki
        if ($existing->hasRole($role)) {
            return [
                'error' => [
                    'success' => false,
                    'error'   => 'role_already_assigned',
                    'message' => "Akun Anda sudah memiliki role {$role}. Silakan login langsung.",
                ],
                'status' => 422,
            ];
        }

        // Append role baru ke user existing
        $existing->assignRole($role);

        // Update name/phone kalau yang baru diisi & yang lama kosong
        $patch = [];
        if (!empty($data['name'])  && empty($existing->name))  $patch['name']  = $data['name'];
        if (!empty($data['phone']) && empty($existing->phone)) $patch['phone'] = $data['phone'];
        if (array_key_exists('is_active', $data) && !$existing->is_active) $patch['is_active'] = true;
        if (!empty($patch)) $existing->update($patch);

        return ['user' => $existing->fresh(), 'isNew' => false];
    }

    // ── Login ─────────────────────────────────────────
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Bedakan: email tidak terdaftar vs password salah
        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error'   => 'email_not_found',
                'message' => 'Email belum terdaftar.',
            ], 404);
        }

        if (!Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            return response()->json([
                'success' => false,
                'error'   => 'wrong_password',
                'message' => 'Password yang Anda masukkan salah.',
            ], 401);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'Akun dinonaktifkan.'], 403);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        //ActivityLogService::log($user->id, 'LOGIN', 'user', $user->id, $request);

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'user' => $this->userResource($user),
                'accessToken' => $token,
                'refreshToken' => null
            ]
        ]);
    }

    // ── Logout ────────────────────────────────────────
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        ActivityLogService::log($request->user()->id, 'LOGOUT', 'user', $request->user()->id, $request);

        return response()->json(['success' => true, 'message' => 'Logout berhasil.']);
    }

    // ── Current User ──────────────────────────────────
    public function me(Request $request)
    {
        $user = $request->user()->load('roles');
        return response()->json(['success' => true, 'data' => $this->userResource($user)]);
    }

    // ── Refresh (Sanctum re-issue) ────────────────────
    public function refresh(Request $request)
    {
        $request->validate(['token' => 'required|string']);
        // With Sanctum, client just sends the old token, we verify and issue new
        // In practice, Sanctum tokens don't expire unless set — this extends session
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Token tidak valid.'], 401);
        }
        $newToken = $user->createToken('auth-token')->plainTextToken;
        return response()->json(['success' => true, 'data' => ['token' => $newToken]]);
    }

    // ── Forgot Password ───────────────────────────────
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();
        if ($user) {
            $token = Str::random(64);
            Cache::put("reset_password:{$token}", $user->id, now()->addHour());

            $resetUrl = config('app.frontend_url') . "/reset-password?token={$token}";
            Mail::to($user->email)->queue(new \App\Mail\PasswordResetMail($user, $resetUrl));
        }

        return response()->json([
            'success' => true,
            'message' => 'Jika email terdaftar, instruksi reset password telah dikirim.',
        ]);
    }

    // ── Reset Password ────────────────────────────────
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token'    => 'required|string',
            'password' => ['required', Password::min(8)],
        ]);

        $userId = Cache::get("reset_password:{$data['token']}");
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Token tidak valid atau sudah kadaluarsa.'], 400);
        }

        User::find($userId)?->update(['password' => Hash::make($data['password'])]);
        Cache::forget("reset_password:{$data['token']}");

        return response()->json(['success' => true, 'message' => 'Password berhasil direset.']);
    }

    // ── Helper ────────────────────────────────────────
    private function userResource(User $user): array
    {
        return [
            'id'     => $user->id,
            'name'   => $user->name,
            'email'  => $user->email,
            'phone'  => $user->phone,
            'avatar' => $user->avatar,
            'role'   => $user->getRoleNames()->first() ?? 'user',
            'roles'  => $user->getRoleNames(),
        ];
    }
}
