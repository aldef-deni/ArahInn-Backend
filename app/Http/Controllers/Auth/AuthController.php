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
    // ── Register ──────────────────────────────────────
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|min:3|max:255',
            'email'    => 'required|email|unique:users',
            'password' => ['required', Password::min(6)],
            'phone'    => 'nullable|string|max:20',
        ]);

        $user  = User::create($data);
        $user->assignRole('user');
        $token = $user->createToken('auth-token')->plainTextToken;

        // Welcome email (queued, non-blocking)
        try {
            Mail::to($user->email)->queue(new \App\Mail\WelcomeMail($user));
        } catch (\Throwable) {}

        ActivityLogService::log($user->id, 'REGISTER', 'user', $user->id, $request);

        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil.',
            'data'    => ['user' => $this->userResource($user), 'token' => $token],
        ], 201);
    }

    // ── Login ─────────────────────────────────────────
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            return response()->json(['success' => false, 'message' => 'Email atau password salah.'], 401);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => 'Akun dinonaktifkan.'], 403);
        }

        // Revoke old tokens (optional: keep last 5)
        $user->tokens()->where('name', 'auth-token')->delete();
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
