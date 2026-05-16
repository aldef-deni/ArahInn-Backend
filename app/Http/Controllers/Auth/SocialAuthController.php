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
        if ($request->boolean('mobile')) {
            $driver = $driver->with(['state' => 'mobile']);
        }
        return $driver->redirect();
    }

    public function callbackGoogle(Request $request)
    {
        $isMobile = $request->input('state') === 'mobile';
        return $this->handleCallback('google', $isMobile);
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
