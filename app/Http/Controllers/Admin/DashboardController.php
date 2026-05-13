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
        $thisMonthStart = now()->startOfMonth();
        $lastMonthStart = now()->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $thisMonthStart->copy()->subSecond();

        $totalUsers = User::count();
        $activeHotels = Hotel::where('status', 'approved')->count();
        $pendingHotels = Hotel::where('status', 'pending')->count();
        $totalBookings = Booking::count();
        $totalRevenue = Payment::where('status', 'settlement')->sum('amount');

        $bookingsThisMonth = Booking::where('created_at', '>=', $thisMonthStart)->count();
        $bookingsLastMonth = Booking::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        $revenueThisMonth = Payment::where('status', 'settlement')
            ->where(function ($query) use ($thisMonthStart) {
                $query->where('paid_at', '>=', $thisMonthStart)
                    ->orWhere(function ($subQuery) use ($thisMonthStart) {
                        $subQuery->whereNull('paid_at')->where('created_at', '>=', $thisMonthStart);
                    });
            })
            ->sum('amount');

        $revenueLastMonth = Payment::where('status', 'settlement')
            ->where(function ($query) use ($lastMonthStart, $lastMonthEnd) {
                $query->whereBetween('paid_at', [$lastMonthStart, $lastMonthEnd])
                    ->orWhere(function ($subQuery) use ($lastMonthStart, $lastMonthEnd) {
                        $subQuery->whereNull('paid_at')->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd]);
                    });
            })
            ->sum('amount');

        $newUsersThisMonth = User::where('created_at', '>=', $thisMonthStart)->count();
        $newUsersLastMonth = User::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        $newHotelsThisMonth = Hotel::where('status', 'approved')->where('created_at', '>=', $thisMonthStart)->count();
        $newHotelsLastMonth = Hotel::where('status', 'approved')->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        $pendingBookings = Booking::where('status', 'pending')->count();

        $recentBookings = Booking::with(['user:id,name', 'hotel:id,name', 'room:id,name,type'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $byStatus = Booking::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_users' => $totalUsers,
                    'total_hotels' => $activeHotels,
                    'active_hotels' => $activeHotels,
                    'pending_hotels' => $pendingHotels,
                    'total_bookings' => $totalBookings,
                    'total_revenue' => $totalRevenue,
                    'bookings_this_month' => $bookingsThisMonth,
                    'revenue_this_month' => $revenueThisMonth,
                    'pending_bookings' => $pendingBookings,
                    'trends' => [
                        'revenue' => $this->calculateTrend($revenueThisMonth, $revenueLastMonth),
                        'bookings' => $this->calculateTrend($bookingsThisMonth, $bookingsLastMonth),
                        'users' => $this->calculateTrend($newUsersThisMonth, $newUsersLastMonth),
                        'hotels' => $this->calculateTrend($newHotelsThisMonth, $newHotelsLastMonth),
                    ],
                ],
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

    private function calculateTrend(float|int $current, float|int $previous): int
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100 : 0;
        }

        return (int) round(((($current - $previous) / $previous) * 100));
    }
}
