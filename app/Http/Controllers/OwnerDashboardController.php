<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerDashboardController extends Controller
{
    public function index(Request $request)
    {
        $owner    = $request->user();
        $allIds   = Hotel::where('owner_id', $owner->id)->pluck('id')->toArray();
        $hotelId  = $request->hotel_id;
        $ids      = $hotelId ? [(int) $hotelId] : $allIds;

        if (empty($ids)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'summary'            => [],
                    'recent_bookings'    => [],
                    'bookings_by_status' => [],
                    'daily_revenue'      => [],
                ],
            ]);
        }

        $thisMonth = now()->startOfMonth();

        // Revenue dihitung dari booking yang sudah pasti dibayar (paid/issued),
        // bukan dari table payments — supaya juga cover booking yang dikonfirmasi
        // manual / tidak melalui gateway.
        $paidStatuses = ['paid', 'issued'];

        // PENTING: pendapatan owner = owner_payout (skema beban diskon: owner tidak
        // menanggung diskon ArahInn). Fallback ke base_price untuk booking historis.
        $ownerRevExpr = DB::raw('COALESCE(owner_payout, base_price)');
        $totalRevenue     = Booking::whereIn('hotel_id', $ids)
            ->whereIn('status', $paidStatuses)
            ->sum($ownerRevExpr);

        $revenueThisMonth = Booking::whereIn('hotel_id', $ids)
            ->whereIn('status', $paidStatuses)
            ->where('created_at', '>=', $thisMonth)
            ->sum($ownerRevExpr);

        // Transparansi: bruto (dibayar customer) & potongan platform bulan ini
        $grossThisMonth   = Booking::whereIn('hotel_id', $ids)
            ->whereIn('status', $paidStatuses)
            ->where('created_at', '>=', $thisMonth)
            ->sum('total_price');

        $totalBookings     = Booking::whereIn('hotel_id', $ids)->count();
        $bookingsThisMonth = Booking::whereIn('hotel_id', $ids)->where('created_at', '>=', $thisMonth)->count();
        $pendingBookings   = Booking::whereIn('hotel_id', $ids)->where('status', 'pending')->count();
        $activeRooms       = Room::whereIn('hotel_id', $ids)->where('is_active', true)->count();

        $recentBookings = Booking::whereIn('hotel_id', $ids)
            ->with(['user:id,name', 'room:id,name,type', 'hotel:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $byStatus = Booking::whereIn('hotel_id', $ids)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $dailyRevenue = Booking::whereIn('hotel_id', $ids)
            ->whereIn('status', $paidStatuses)
            ->where('created_at', '>=', $thisMonth)
            ->get()
            ->groupBy(fn($b) => $b->created_at->format('Y-m-d'))
            ->map(fn($g, $d) => ['date' => $d, 'amount' => (float) $g->sum(fn($b) => $b->owner_payout ?? $b->base_price)])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_revenue'      => (float) $totalRevenue,      // netto diterima owner (base_price)
                    'revenue_this_month' => (float) $revenueThisMonth,  // netto bulan ini
                    'gross_this_month'   => (float) $grossThisMonth,    // bruto dibayar customer
                    'commission_this_month' => (float) ($grossThisMonth - $revenueThisMonth), // potongan platform+pajak
                    'total_bookings'     => $totalBookings,
                    'bookings_this_month'=> $bookingsThisMonth,
                    'pending_bookings'   => $pendingBookings,
                    'active_rooms'       => $activeRooms,
                ],
                'recent_bookings'    => $recentBookings,
                'bookings_by_status' => $byStatus,
                'daily_revenue'      => $dailyRevenue,
            ],
        ]);
    }
}
