<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use App\Models\Hotel;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin Analytics — aggregated metrics from existing tables.
 *
 * No new events tracking needed; queries derive everything from
 * users / bookings / device_tokens. Safe to call without mobile rebuild.
 *
 * All endpoints accept ?days=N (default 30, max 365) for time range.
 */
class AnalyticsController extends Controller
{
    private function range(Request $request): array
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));
        $end   = Carbon::now()->endOfDay();
        $start = Carbon::now()->subDays($days - 1)->startOfDay();
        return [$start, $end, $days];
    }

    /**
     * Top-level summary cards.
     * GET /admin/analytics/overview?days=30
     */
    public function overview(Request $request)
    {
        [$start, $end, $days] = $this->range($request);
        $prevStart = $start->copy()->subDays($days);
        $prevEnd   = $start->copy()->subSecond();

        // Users
        $newUsers     = User::whereBetween('created_at', [$start, $end])->count();
        $prevNewUsers = User::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $totalUsers = User::count();

        // Bookings
        $bookings = Booking::whereBetween('created_at', [$start, $end])->count();
        $prevBookings = Booking::whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $paidBookings = Booking::whereIn('status', ['paid', 'issued'])
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Revenue (only paid/issued)
        $revenue = (float) Booking::whereIn('status', ['paid', 'issued'])
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_price');

        $prevRevenue = (float) Booking::whereIn('status', ['paid', 'issued'])
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->sum('total_price');

        // Active users (rough estimate via Sanctum token last_used_at)
        $dau = $this->dauCount(Carbon::now()->startOfDay(), Carbon::now()->endOfDay());
        $mau = $this->dauCount(
            Carbon::now()->subDays(29)->startOfDay(),
            Carbon::now()->endOfDay()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'rangeDays'    => $days,
                'newUsers'     => $newUsers,
                'newUsersPrev' => $prevNewUsers,
                'totalUsers'   => $totalUsers,
                'bookings'     => $bookings,
                'bookingsPrev' => $prevBookings,
                'paidBookings' => $paidBookings,
                'revenue'      => $revenue,
                'revenuePrev'  => $prevRevenue,
                'dau'          => $dau,
                'mau'          => $mau,
                'conversionRate' => $bookings > 0
                    ? round(($paidBookings / $bookings) * 100, 1)
                    : 0,
            ],
        ]);
    }

    /**
     * Daily user metrics: signups, DAU, MAU time-series.
     * GET /admin/analytics/users?days=30
     */
    public function users(Request $request)
    {
        [$start, $end, $days] = $this->range($request);

        // Signups per day
        $signupsRaw = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');

        // DAU per day — count unique user_id from personal_access_tokens last_used_at
        $dauRaw = DB::table('personal_access_tokens')
            ->selectRaw('DATE(last_used_at) as date, COUNT(DISTINCT tokenable_id) as count')
            ->whereBetween('last_used_at', [$start, $end])
            ->where('tokenable_type', 'App\\Models\\User')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');

        // Build complete daily series (fill missing days with 0)
        $series = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $dateKey = $cursor->format('Y-m-d');
            $series[] = [
                'date'    => $dateKey,
                'signups' => (int) ($signupsRaw[$dateKey] ?? 0),
                'dau'     => (int) ($dauRaw[$dateKey] ?? 0),
            ];
            $cursor->addDay();
        }

        // Role breakdown
        $roleBreakdown = User::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role');

        return response()->json([
            'success' => true,
            'data' => [
                'series'        => $series,
                'roleBreakdown' => $roleBreakdown,
                'rangeDays'     => $days,
            ],
        ]);
    }

    /**
     * Booking metrics: count, revenue, status breakdown, conversion funnel.
     * GET /admin/analytics/bookings?days=30
     */
    public function bookings(Request $request)
    {
        [$start, $end, $days] = $this->range($request);

        // Daily bookings + revenue
        $dailyRaw = Booking::selectRaw('DATE(created_at) as date,
                COUNT(*) as total,
                COUNT(CASE WHEN status IN ("paid","issued") THEN 1 END) as paid,
                COUNT(CASE WHEN status = "pending" THEN 1 END) as pending,
                COUNT(CASE WHEN status = "canceled" THEN 1 END) as canceled,
                SUM(CASE WHEN status IN ("paid","issued") THEN total_price ELSE 0 END) as revenue
            ')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $series = [];
        $cursor = $start->copy();
        while ($cursor <= $end) {
            $dateKey = $cursor->format('Y-m-d');
            $row = $dailyRaw[$dateKey] ?? null;
            $series[] = [
                'date'     => $dateKey,
                'total'    => (int) ($row->total ?? 0),
                'paid'     => (int) ($row->paid ?? 0),
                'pending'  => (int) ($row->pending ?? 0),
                'canceled' => (int) ($row->canceled ?? 0),
                'revenue'  => (float) ($row->revenue ?? 0),
            ];
            $cursor->addDay();
        }

        // Status breakdown (for pie chart)
        $statusBreakdown = Booking::selectRaw('status, COUNT(*) as count')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('status')
            ->pluck('count', 'status');

        // Conversion funnel: pending → paid → issued
        // Note: a booking that's "issued" was also paid at some point,
        // so we count cumulatively for funnel display.
        $totalCreated = Booking::whereBetween('created_at', [$start, $end])->count();
        $reachedPaid  = Booking::whereIn('status', ['paid', 'issued'])
            ->whereBetween('created_at', [$start, $end])->count();
        $reachedIssued = Booking::where('status', 'issued')
            ->whereBetween('created_at', [$start, $end])->count();

        $funnel = [
            ['stage' => 'Booking dibuat', 'count' => $totalCreated],
            ['stage' => 'Pembayaran',     'count' => $reachedPaid],
            ['stage' => 'Voucher dikirim', 'count' => $reachedIssued],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'series'          => $series,
                'statusBreakdown' => $statusBreakdown,
                'funnel'          => $funnel,
                'rangeDays'       => $days,
            ],
        ]);
    }

    /**
     * Top hotels by booking count.
     * GET /admin/analytics/top-hotels?days=30&limit=10
     */
    public function topHotels(Request $request)
    {
        [$start, $end, $days] = $this->range($request);
        $limit = (int) $request->query('limit', 10);
        $limit = max(1, min(50, $limit));

        $topHotels = Booking::selectRaw('
                hotel_id,
                COUNT(*) as booking_count,
                COUNT(CASE WHEN status IN ("paid","issued") THEN 1 END) as paid_count,
                SUM(CASE WHEN status IN ("paid","issued") THEN total_price ELSE 0 END) as revenue
            ')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('hotel_id')
            ->groupBy('hotel_id')
            ->orderByDesc('booking_count')
            ->limit($limit)
            ->with('hotel:id,name,city,category')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'hotels'    => $topHotels,
                'rangeDays' => $days,
            ],
        ]);
    }

    /**
     * Helper: count unique active users in a date range.
     */
    private function dauCount(Carbon $start, Carbon $end): int
    {
        return (int) DB::table('personal_access_tokens')
            ->whereBetween('last_used_at', [$start, $end])
            ->where('tokenable_type', 'App\\Models\\User')
            ->distinct('tokenable_id')
            ->count('tokenable_id');
    }
}
