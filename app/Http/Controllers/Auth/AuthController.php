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
        // Model AKUN TERPISAH (per keputusan 2026-06-04): email sama boleh punya
        // akun customer DAN akun owner terpisah. Tidak menambah role ke akun existing.
        // Yang dicegah hanya duplikat email + role yang SAMA.
        $dup = User::where('email', $data['email'])->where('primary_role', $role)->first();
        if ($dup) {
            return [
                'error' => [
                    'success' => false,
                    'error'   => 'role_already_assigned',
                    'message' => "Email ini sudah terdaftar sebagai {$role}. Silakan login langsung.",
                ],
                'status' => 422,
            ];
        }

        // Buat akun BARU khusus role ini (terpisah dari akun customer bila ada).
        $user = User::create($data + ['primary_role' => $role]);
        $user->assignRole($role);

        return ['user' => $user, 'isNew' => true];
    }

    // ── Login ─────────────────────────────────────────
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'portal'   => 'nullable|string', // 'owner' = login dari Extranet (pilih akun owner)
        ]);

        $isOwnerPortal = ($data['portal'] ?? null) === 'owner';

        // Model akun terpisah: email sama bisa punya akun owner & customer terpisah.
        // Portal extranet → cari akun primary_role='owner'. Default → akun non-owner.
        $probe = User::where('email', $data['email']);
        if ($isOwnerPortal) {
            $probe->where('primary_role', 'owner');
        }
        if (!$probe->exists()) {
            return response()->json([
                'success' => false,
                'error'   => 'email_not_found',
                'message' => $isOwnerPortal
                    ? 'Belum ada akun pemilik (Extranet) untuk email ini.'
                    : 'Email belum terdaftar.',
            ], 404);
        }

        // Auth::attempt: key selain 'password' jadi kondisi where → scope akun owner.
        $creds = ['email' => $data['email'], 'password' => $data['password']];
        if ($isOwnerPortal) {
            $creds['primary_role'] = 'owner';
        }

        if (!Auth::attempt($creds)) {
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
