<?php

namespace App\Http\Controllers;

use App\Models\OtaNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = OtaNotification::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->limit ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $notifications->items(),
            'pagination' => [
                'total' => $notifications->total(),
                'page'  => $notifications->currentPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        $count = OtaNotification::where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return response()->json(['success' => true, 'data' => ['count' => $count]]);
    }

    public function markRead(Request $request, string $id)
    {
        $notif = OtaNotification::where('user_id', $request->user()->id)->findOrFail($id);
        $notif->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request)
    {
        OtaNotification::where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }
}
