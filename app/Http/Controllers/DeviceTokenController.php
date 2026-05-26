<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Register / refresh device push token untuk user yang login.
     * Idempoten: kalau token sudah ada → update last_seen_at + user_id.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'token'       => 'required|string|max:255',
            'platform'    => 'nullable|string|in:android,ios,web',
            'device_id'   => 'nullable|string|max:100',
            'app_version' => 'nullable|string|max:30',
        ]);

        $token = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id'      => $request->user()->id,
                'platform'     => $data['platform']    ?? 'android',
                'device_id'    => $data['device_id']   ?? null,
                'app_version'  => $data['app_version'] ?? null,
                'is_active'    => true,
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['success' => true, 'data' => $token]);
    }

    /**
     * Unregister token (logout / uninstall).
     */
    public function unregister(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|max:255']);

        DeviceToken::where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->update(['is_active' => false]);

        return response()->json(['success' => true]);
    }
}
