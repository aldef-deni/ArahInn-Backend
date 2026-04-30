<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $thisMonth = now()->startOfMonth();

        [$totalUsers, $totalHotels, $totalBookings, $totalRevenue,
         $bookingsThisMonth, $revenueThisMonth, $pendingBookings,
         $recentBookings, $byStatus] = [
            User::count(),
            Hotel::where('status', 'approved')->count(),
            Booking::count(),
            Payment::where('status', 'settlement')->sum('amount'),
            Booking::where('created_at', '>=', $thisMonth)->count(),
            Payment::where('status', 'settlement')->where('created_at', '>=', $thisMonth)->sum('amount'),
            Booking::where('status', 'pending')->count(),
            Booking::with(['user:id,name', 'hotel:id,name'])
                ->orderBy('created_at', 'desc')->limit(10)->get(),
            Booking::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')->pluck('count', 'status'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => compact(
                    'totalUsers', 'totalHotels', 'totalBookings', 'totalRevenue',
                    'bookingsThisMonth', 'revenueThisMonth', 'pendingBookings'
                ),
                'recent_bookings' => $recentBookings,
                'bookings_by_status' => $byStatus,
            ],
        ]);
    }

    public function logs(Request $request)
    {
        $query = ActivityLog::with('user:id,name,email')
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->action, fn($q) => $q->where('action', 'like', "%{$request->action}%"))
            ->when(
                $request->from && $request->to,
                fn($q) => $q->whereBetween('created_at', [$request->from, $request->to])
            )
            ->orderBy('created_at', 'desc');

        $result = $query->paginate($request->limit ?? 50);

        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'pagination' => ['total' => $result->total()],
        ]);
    }
}
