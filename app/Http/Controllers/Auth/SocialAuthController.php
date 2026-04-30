<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function redirectGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callbackGoogle()
    {
        return $this->handleCallback('google');
    }

    public function redirectFacebook()
    {
        return Socialite::driver('facebook')->stateless()->redirect();
    }

    public function callbackFacebook()
    {
        return $this->handleCallback('facebook');
    }

    private function handleCallback(string $provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
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

        $token       = $user->createToken('auth-token')->plainTextToken;
        $frontendUrl = config('app.frontend_url');

        return redirect("{$frontendUrl}/auth/callback?token={$token}");
    }
}
