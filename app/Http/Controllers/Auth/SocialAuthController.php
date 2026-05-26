<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirectGoogle(Request $request)
    {
        $driver = Socialite::driver('google')->stateless();

        // Param tambahan untuk OAuth Google:
        // - prompt=select_account  → selalu tampilkan account picker (tidak auto-login)
        // - access_type=online     → tidak perlu refresh token (lebih ringan)
        // - display=page (mobile)  → paksa Google render UI full-page (bukan popup kecil)
        $extra = [
            'prompt'      => 'select_account',
            'access_type' => 'online',
        ];

        if ($request->boolean('mobile')) {
            $extra['state']   = 'mobile';
            $extra['display'] = 'page';
        }

        return $driver->with($extra)->redirect();
    }

    public function callbackGoogle(Request $request)
    {
        $isMobile = $request->input('state') === 'mobile';
        return $this->handleCallback('google', $isMobile);
    }

    /**
     * Native Google Sign-In dari mobile app.
     *
     * Flow:
     *   1. Mobile pakai @react-native-google-signin/google-signin (Android SDK native)
     *      → user pilih akun di native picker → SDK return id_token (JWT)
     *   2. Mobile POST id_token ke endpoint ini
     *   3. Backend verify id_token via Google JWKS, pastikan audience = WEB_CLIENT_ID
     *   4. Find-or-create user, return Sanctum personal access token
     *
     * Body:
     *   id_token : string (required) — JWT dari Google
     *   email, name, photo : string (optional, hint saja)
     */
    public function mobileGoogle(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $idToken = $request->input('id_token');
        $expectedAud = config('services.google.client_id');

        if (!$expectedAud) {
            return response()->json([
                'success' => false,
                'message' => 'Server belum dikonfigurasi: GOOGLE_CLIENT_ID kosong.',
            ], 500);
        }

        // Verify ID token via Google tokeninfo endpoint.
        // Alternative: pakai package google/apiclient, tapi tokeninfo HTTP call lebih ringan.
        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(10)
                ->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);

            if (!$resp->successful()) {
                logger()->warning('Google ID token verification failed', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Token Google tidak valid.',
                ], 401);
            }

            $payload = $resp->json();

            // Audience HARUS match WEB_CLIENT_ID kita.
            // Android SDK signed token-nya untuk Web Client (via webClientId/serverClientId config).
            $aud = $payload['aud'] ?? null;
            if ($aud !== $expectedAud) {
                logger()->warning('Google ID token aud mismatch', [
                    'expected' => $expectedAud,
                    'got'      => $aud,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Token Google bukan untuk aplikasi ini.',
                ], 401);
            }

            // Cek expiry — tokeninfo seharusnya sudah reject expired, tapi defensive.
            if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token Google sudah kedaluwarsa.',
                ], 401);
            }

            // Email harus terverifikasi
            $email   = $payload['email'] ?? null;
            $emailOk = filter_var($payload['email_verified'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
            $sub     = $payload['sub'] ?? null;  // Google user id

            if (!$email || !$emailOk || !$sub) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email Google tidak terverifikasi.',
                ], 401);
            }

            $name   = $payload['name']    ?? $request->input('name')  ?? explode('@', $email)[0];
            $avatar = $payload['picture'] ?? $request->input('photo') ?? null;

        } catch (\Throwable $e) {
            logger()->error('Google ID token verification exception', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memverifikasi token Google.',
            ], 500);
        }

        // Find-or-create user (logic sama dengan handleCallback)
        $user = User::where('oauth_provider', 'google')
                    ->where('oauth_id', $sub)
                    ->first();

        if (!$user) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update([
                    'oauth_provider' => 'google',
                    'oauth_id'       => $sub,
                ]);
            } else {
                $user = User::create([
                    'name'           => $name,
                    'email'          => $email,
                    'avatar'         => $avatar,
                    'oauth_provider' => 'google',
                    'oauth_id'       => $sub,
                    'password'       => null,
                ]);
                $user->assignRole('user');
            }
        }

        $token = $user->createToken('mobile-google')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user'  => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'avatar'=> $user->avatar,
                ],
            ],
        ]);
    }

    public function redirectFacebook(Request $request)
    {
        $driver = Socialite::driver('facebook')->stateless();
        if ($request->boolean('mobile')) {
            $driver = $driver->with(['state' => 'mobile']);
        }
        return $driver->redirect();
    }

    public function callbackFacebook(Request $request)
    {
        $isMobile = $request->input('state') === 'mobile';
        return $this->handleCallback('facebook', $isMobile);
    }

    private function handleCallback(string $provider, bool $isMobile = false)
    {
        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            if ($isMobile) {
                return redirect('arahinn://auth/callback?error=oauth_failed');
            }
            $frontendUrl = config('app.frontend_url');
            return redirect("{$frontendUrl}/login?error=oauth_failed");
        }

        // Find or create user
        $user = User::where('oauth_provider', $provider)
                    ->where('oauth_id', $socialUser->getId())
                    ->first();

        if (!$user) {
            $user = User::where('email', $socialUser->getEmail())->first();

            if ($user) {
                $user->update(['oauth_provider' => $provider, 'oauth_id' => $socialUser->getId()]);
            } else {
                $user = User::create([
                    'name'           => $socialUser->getName(),
                    'email'          => $socialUser->getEmail(),
                    'avatar'         => $socialUser->getAvatar(),
                    'oauth_provider' => $provider,
                    'oauth_id'       => $socialUser->getId(),
                    'password'       => null,
                ]);
                $user->assignRole('user');
            }
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        if ($isMobile) {
            return redirect("arahinn://auth/callback?token={$token}");
        }

        $frontendUrl = config('app.frontend_url');
        return redirect("{$frontendUrl}/auth/callback?token={$token}");
    }
}
