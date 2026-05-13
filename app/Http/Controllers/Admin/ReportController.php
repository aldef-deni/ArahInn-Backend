<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function revenue(Request $request)
    {
        $from = $request->from ? now()->parse($request->from) : now()->startOfMonth();
        $to   = $request->to ? now()->parse($request->to) : now();

        $query = Payment::where('status', 'settlement')
            ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$from, $to])
            ->with('booking:id,booking_code,guest_name,total_price,hotel_id')
            ->orderByRaw('COALESCE(paid_at, created_at) asc');

        if ($request->hotel_id) {
            $query->whereHas('booking', fn($q) => $q->where('hotel_id', $request->hotel_id));
        }

        if ($request->owner_id) {
            $query->whereHas('booking', fn($q) =>
                $q->whereHas('hotel', fn($q2) => $q2->where('owner_id', $request->owner_id))
            );
        }

        $payments = $query->get();
        $totalRevenue = $payments->sum('amount');

        $daily = $payments
            ->groupBy(fn($payment) => ($payment->paid_at ?? $payment->created_at)?->format('Y-m-d'))
            ->map(fn($group, $date) => [
                'date' => $date,
                'count' => $group->count(),
                'amount' => $group->sum('amount'),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => $totalRevenue,
                'total_transactions' => $payments->count(),
                'period' => ['from' => $from, 'to' => $to],
                'daily' => $daily,
                'transactions' => $payments,
            ],
        ]);
    }

    public function bookings(Request $request)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to   = $request->to ?? now()->toDateString();

        $query = Booking::with(['user:id,name,email', 'hotel:id,name,city', 'room:id,name,type'])
            ->whereBetween('created_at', [$from, $to]);

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->hotel_id) {
            $query->where('hotel_id', $request->hotel_id);
        }
        if ($request->owner_id) {
            $query->whereHas('hotel', fn($q) => $q->where('owner_id', $request->owner_id));
        }

        $bookings   = $query->orderBy('created_at', 'desc')->get();
        $totalValue = $bookings->sum('total_price');

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $bookings->count(),
                'total_value' => $totalValue,
                'period' => ['from' => $from, 'to' => $to],
                'bookings' => $bookings,
            ],
        ]);
    }

    public function canceled(Request $request)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to   = $request->to ?? now()->toDateString();

        $canceledQuery = Booking::with(['user:id,name,email', 'hotel:id,name'])
            ->where('status', 'canceled')
            ->whereBetween('canceled_at', [$from, $to]);

        $refundedQuery = Booking::with(['user:id,name,email', 'hotel:id,name'])
            ->where('status', 'refunded')
            ->whereBetween('updated_at', [$from, $to]);

        foreach ([$canceledQuery, $refundedQuery] as $q) {
            if ($request->hotel_id) {
                $q->where('hotel_id', $request->hotel_id);
            }
            if ($request->owner_id) {
                $q->whereHas('hotel', fn($sub) => $sub->where('owner_id', $request->owner_id));
            }
        }

        $canceled    = $canceledQuery->get();
        $refunded    = $refundedQuery->get();
        $lostRevenue = $canceled->sum('total_price') + $refunded->sum('total_price');

        return response()->json([
            'success' => true,
            'data' => [
                'canceled' => $canceled,
                'refunded' => $refunded,
                'total_canceled' => $canceled->count(),
                'total_refunded' => $refunded->count(),
                'lost_revenue' => $lostRevenue,
            ],
        ]);
    }
}
