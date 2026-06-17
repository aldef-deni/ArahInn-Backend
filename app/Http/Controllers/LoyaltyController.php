<?php

namespace App\Http\Controllers;

use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(private LoyaltyService $loyalty) {}

    public function balance(Request $request)
    {
        $balance = $this->loyalty->getBalance($request->user()->id);
        return response()->json(['success' => true, 'data' => ['balance' => $balance]]);
    }

    public function summary(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => $this->loyalty->summary($request->user()->id),
        ]);
    }

    public function history(Request $request)
    {
        $history = $this->loyalty->getHistory($request->user()->id, $request->page ?? 1);
        return response()->json(['success' => true, 'data' => $history->items(), 'pagination' => ['total' => $history->total()]]);
    }

    public function redeem(Request $request)
    {
        $data = $request->validate([
            'points' => 'required|integer|min:1',
            'booking_id' => 'required|integer',
        ]);

        $this->loyalty->redeem($request->user()->id, $data['points'], $data['booking_id']);

        return response()->json(['success' => true, 'message' => "{$data['points']} poin berhasil digunakan."]);
    }
}
