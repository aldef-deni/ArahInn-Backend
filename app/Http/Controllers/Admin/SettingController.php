<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    public function getGateways()
    {
        $settings = Cache::get('settings:payment_gateways', ['active' => 'midtrans', 'available' => ['midtrans', 'xendit']]);
        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function setGateway(Request $request)
    {
        $data = $request->validate(['active' => 'required|in:midtrans,xendit']);
        $settings = [
            'active' => $data['active'],
            'available' => ['midtrans', 'xendit'],
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ];

        Cache::forever('settings:payment_gateways', $settings);

        return response()->json(['success' => true, 'data' => $settings]);
    }
}
