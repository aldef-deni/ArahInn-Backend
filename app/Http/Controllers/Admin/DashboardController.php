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

        // ── Pendapatan KOMISI ArahInn (laba platform) ───────────────────────
        // Per booking: markup_amount − (base_price × 2% PPh). Dihitung dari
        // pembayaran settlement, kecuali booking refunded/canceled.
        // Konsisten dengan ReportController::profit & halaman Laba Platform.
        $pphRate    = 0.02;
        // Pakai commission_profit (skema beban diskon, bisa minus karena nalangin promo
        // ArahInn) bila ada; fallback ke rumus lama untuk booking historis.
        $profitExpr = "COALESCE(bookings.commission_profit, GREATEST(bookings.markup_amount - (bookings.base_price * {$pphRate}), 0))";
        $commBase   = fn() => DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('payments.status', 'settlement')
            ->whereNotIn('bookings.status', ['refunded', 'canceled']);
        $payDate    = 'COALESCE(payments.paid_at, payments.created_at)';

        $commissionRevenue   = (float) $commBase()->sum(DB::raw($profitExpr));
        $commissionThisMonth = (float) $commBase()
            ->where(DB::raw($payDate), '>=', $thisMonthStart)
            ->sum(DB::raw($profitExpr));
        $commissionLastMonth = (float) $commBase()
            ->whereBetween(DB::raw($payDate), [$lastMonthStart, $lastMonthEnd])
            ->sum(DB::raw($profitExpr));

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

        // ── PPOB (transaksi berhasil) ───────────────────────────────────────
        $ppobBase = fn() => DB::table('ppob_transactions')->where('status', 'success');
        $ppobOmzet       = (float) $ppobBase()->sum('total_amount');
        $ppobProfit      = (float) $ppobBase()->sum(DB::raw('price_sell - price_buy'));
        $ppobCount       = (int)   $ppobBase()->count();
        $ppobOmzetMonth  = (float) $ppobBase()->where('created_at', '>=', $thisMonthStart)->sum('total_amount');
        $ppobProfitMonth = (float) $ppobBase()->where('created_at', '>=', $thisMonthStart)->sum(DB::raw('price_sell - price_buy'));
        $ppobCountMonth  = (int)   $ppobBase()->where('created_at', '>=', $thisMonthStart)->count();
        $ppobOmzetLast   = (float) $ppobBase()->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->sum('total_amount');

        // ── Tiket Travel (e-tiket terbit) ───────────────────────────────────
        $travelBase = fn() => DB::table('travel_bookings')->where('status', 'issued');
        $travelOmzet       = (float) $travelBase()->sum('total_price');
        $travelProfit      = (float) $travelBase()->sum('markup');   // biaya layanan = laba travel
        $travelCount       = (int)   $travelBase()->count();
        $travelOmzetMonth  = (float) $travelBase()->where('created_at', '>=', $thisMonthStart)->sum('total_price');
        $travelProfitMonth = (float) $travelBase()->where('created_at', '>=', $thisMonthStart)->sum('markup');
        $travelCountMonth  = (int)   $travelBase()->where('created_at', '>=', $thisMonthStart)->count();
        $travelOmzetLast   = (float) $travelBase()->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->sum('total_price');

        // ── Akomodasi (jumlah pesanan yang terbayar/terbit) ─────────────────
        $akoCount      = (int) Booking::whereIn('status', ['paid', 'issued'])->count();
        $akoCountMonth = (int) Booking::whereIn('status', ['paid', 'issued'])->where('created_at', '>=', $thisMonthStart)->count();

        // ── Total semua channel ─────────────────────────────────────────────
        $totalOmzetAll       = (float) $totalRevenue + $ppobOmzet + $travelOmzet;
        $totalOmzetAllMonth  = (float) $revenueThisMonth + $ppobOmzetMonth + $travelOmzetMonth;
        $totalOmzetAllLast   = (float) $revenueLastMonth + $ppobOmzetLast + $travelOmzetLast;
        $totalProfitAll      = round($commissionRevenue + $ppobProfit + $travelProfit, 2);
        $totalProfitAllMonth = round($commissionThisMonth + $ppobProfitMonth + $travelProfitMonth, 2);
        $totalCountAll       = $akoCount + $ppobCount + $travelCount;
        $totalCountAllMonth  = $akoCountMonth + $ppobCountMonth + $travelCountMonth;

        // ── Tren omzet 6 bulan per channel (untuk stacked area chart) ───────
        $since = now()->copy()->subMonths(5)->startOfMonth();
        $akoMonthly = DB::table('payments')->where('status', 'settlement')
            ->where(DB::raw($payDate), '>=', $since)
            ->select(DB::raw("DATE_FORMAT(COALESCE(payments.paid_at, payments.created_at), '%Y-%m') as m"), DB::raw('SUM(amount) as v'))
            ->groupBy('m')->pluck('v', 'm');
        $ppobMonthly = DB::table('ppob_transactions')->where('status', 'success')
            ->where('created_at', '>=', $since)
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as m"), DB::raw('SUM(total_amount) as v'))
            ->groupBy('m')->pluck('v', 'm');
        $travelMonthly = DB::table('travel_bookings')->where('status', 'issued')
            ->where('created_at', '>=', $since)
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as m"), DB::raw('SUM(total_price) as v'))
            ->groupBy('m')->pluck('v', 'm');

        $monthlyByChannel = [];
        foreach (range(5, 0) as $i) {
            $key = now()->copy()->subMonths($i)->format('Y-m');
            $monthlyByChannel[] = [
                'month'     => $key,
                'akomodasi' => (float) ($akoMonthly[$key] ?? 0),
                'ppob'      => (float) ($ppobMonthly[$key] ?? 0),
                'travel'    => (float) ($travelMonthly[$key] ?? 0),
            ];
        }

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
                    'commission_revenue' => round($commissionRevenue, 2),       // laba komisi ArahInn (total)
                    'commission_this_month' => round($commissionThisMonth, 2),  // laba komisi bulan ini
                    'pending_bookings' => $pendingBookings,
                    'trends' => [
                        'revenue' => $this->calculateTrend($revenueThisMonth, $revenueLastMonth),
                        'commission' => $this->calculateTrend($commissionThisMonth, $commissionLastMonth),
                        'bookings' => $this->calculateTrend($bookingsThisMonth, $bookingsLastMonth),
                        'users' => $this->calculateTrend($newUsersThisMonth, $newUsersLastMonth),
                        'hotels' => $this->calculateTrend($newHotelsThisMonth, $newHotelsLastMonth),
                    ],
                ],
                'recent_bookings' => $recentBookings,
                'bookings_by_status' => $byStatus,
                // ── Ringkasan semua channel (akomodasi + PPOB + travel) ──
                'totals_all' => [
                    'omzet'        => $totalOmzetAll,
                    'omzet_month'  => $totalOmzetAllMonth,
                    'omzet_trend'  => $this->calculateTrend($totalOmzetAllMonth, $totalOmzetAllLast),
                    'profit'       => $totalProfitAll,
                    'profit_month' => $totalProfitAllMonth,
                    'count'        => $totalCountAll,
                    'count_month'  => $totalCountAllMonth,
                ],
                'channels' => [
                    'akomodasi' => [
                        'omzet'        => (float) $totalRevenue,
                        'omzet_month'  => (float) $revenueThisMonth,
                        'profit'       => round($commissionRevenue, 2),
                        'profit_month' => round($commissionThisMonth, 2),
                        'count'        => $akoCount,
                        'count_month'  => $akoCountMonth,
                        'trend'        => $this->calculateTrend($revenueThisMonth, $revenueLastMonth),
                    ],
                    'ppob' => [
                        'omzet'        => $ppobOmzet,
                        'omzet_month'  => $ppobOmzetMonth,
                        'profit'       => round($ppobProfit, 2),
                        'profit_month' => round($ppobProfitMonth, 2),
                        'count'        => $ppobCount,
                        'count_month'  => $ppobCountMonth,
                        'trend'        => $this->calculateTrend($ppobOmzetMonth, $ppobOmzetLast),
                    ],
                    'travel' => [
                        'omzet'        => $travelOmzet,
                        'omzet_month'  => $travelOmzetMonth,
                        'profit'       => round($travelProfit, 2),
                        'profit_month' => round($travelProfitMonth, 2),
                        'count'        => $travelCount,
                        'count_month'  => $travelCountMonth,
                        'trend'        => $this->calculateTrend($travelOmzetMonth, $travelOmzetLast),
                    ],
                ],
                'monthly_by_channel' => $monthlyByChannel,
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
