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

    /** Laporan PPOB — transaksi berhasil (success) dalam periode. */
    public function ppob(Request $request)
    {
        $from = $request->from ? now()->parse($request->from)->startOfDay() : now()->startOfMonth();
        $to   = $request->to ? now()->parse($request->to)->endOfDay() : now();

        $rows = DB::table('ppob_transactions')
            ->leftJoin('users', 'users.id', '=', 'ppob_transactions.user_id')
            ->where('ppob_transactions.status', 'success')
            ->whereBetween('ppob_transactions.created_at', [$from, $to])
            ->orderBy('ppob_transactions.created_at')
            ->get([
                'ppob_transactions.id', 'ppob_transactions.trx_code', 'ppob_transactions.product_name',
                'ppob_transactions.customer_number', 'ppob_transactions.customer_name',
                'ppob_transactions.total_amount', 'ppob_transactions.price_buy', 'ppob_transactions.price_sell',
                'ppob_transactions.serial_number', 'ppob_transactions.status', 'ppob_transactions.created_at',
                'users.name as user_name', 'users.email as user_email',
            ]);

        $profitOf = fn($r) => (float) $r->price_sell - (float) $r->price_buy;
        $totalOmzet  = (float) $rows->sum('total_amount');
        $totalProfit = (float) $rows->sum($profitOf);

        $daily = $rows
            ->groupBy(fn($r) => \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d'))
            ->map(fn($g, $date) => [
                'date'   => $date,
                'count'  => $g->count(),
                'amount' => (float) $g->sum('total_amount'),
                'profit' => (float) $g->sum($profitOf),
            ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total_omzet'        => $totalOmzet,
                'total_profit'       => round($totalProfit, 2),
                'total_transactions' => $rows->count(),
                'period'             => ['from' => $from, 'to' => $to],
                'daily'              => $daily,
                'transactions'       => $rows,
            ],
        ]);
    }

    /** Laporan Tiket Travel — e-tiket terbit (issued) dalam periode. */
    public function travel(Request $request)
    {
        $from = $request->from ? now()->parse($request->from)->startOfDay() : now()->startOfMonth();
        $to   = $request->to ? now()->parse($request->to)->endOfDay() : now();

        $rows = DB::table('travel_bookings')
            ->leftJoin('users', 'users.id', '=', 'travel_bookings.user_id')
            ->where('travel_bookings.status', 'issued')
            ->whereBetween('travel_bookings.created_at', [$from, $to])
            ->orderBy('travel_bookings.created_at')
            ->get([
                'travel_bookings.id', 'travel_bookings.code', 'travel_bookings.moda',
                'travel_bookings.origin', 'travel_bookings.destination', 'travel_bookings.service_name',
                'travel_bookings.vendor_price', 'travel_bookings.markup', 'travel_bookings.admin_fee',
                'travel_bookings.total_price', 'travel_bookings.pax', 'travel_bookings.depart_date',
                'travel_bookings.status', 'travel_bookings.created_at',
                'users.name as user_name', 'users.email as user_email',
            ]);

        $totalOmzet  = (float) $rows->sum('total_price');
        $totalProfit = (float) $rows->sum('markup');   // biaya layanan = laba travel

        $daily = $rows
            ->groupBy(fn($r) => \Illuminate\Support\Carbon::parse($r->created_at)->format('Y-m-d'))
            ->map(fn($g, $date) => [
                'date'   => $date,
                'count'  => $g->count(),
                'amount' => (float) $g->sum('total_price'),
                'profit' => (float) $g->sum('markup'),
            ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total_omzet'        => $totalOmzet,
                'total_profit'       => round($totalProfit, 2),
                'total_transactions' => $rows->count(),
                'period'             => ['from' => $from, 'to' => $to],
                'daily'              => $daily,
                'transactions'       => $rows,
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

    /**
     * Laba/keuntungan platform dari KOMISI akomodasi.
     *
     * Markup tiap booking ("Pajak & Others") = base × (commission% + 2% PPh).
     * Jadi laba komisi murni platform = markup_amount − (base_price × 2% PPh).
     * (PPh & PPN adalah pajak yang disetor, bukan laba platform.)
     *
     * Dihitung dari pembayaran yang sudah settlement (uang benar-benar masuk),
     * mengecualikan booking yang sudah refunded.
     */
    public function profit(Request $request)
    {
        $from = $request->from ? now()->parse($request->from)->startOfDay() : now()->startOfMonth();
        $to   = $request->to ? now()->parse($request->to)->endOfDay() : now()->endOfDay();

        $pphRate = 0.02; // PPh 2% (konsisten dgn PricingService::PPH_PERCENT)

        $query = Payment::where('status', 'settlement')
            ->whereBetween(DB::raw('COALESCE(paid_at, created_at)'), [$from, $to])
            ->whereHas('booking', fn($q) => $q->whereNotIn('status', ['refunded', 'canceled']))
            ->with(['booking' => fn($q) => $q->select(
                'id', 'booking_code', 'guest_name', 'hotel_id',
                'base_price', 'markup_amount', 'total_price', 'status', 'created_at',
                'discount_arahinn', 'discount_owner', 'owner_payout', 'commission_profit'
            )->with('hotel:id,name,city,owner_id')])
            ->orderByRaw('COALESCE(paid_at, created_at) asc');

        if ($request->hotel_id) {
            $query->whereHas('booking', fn($q) => $q->where('hotel_id', $request->hotel_id));
        }
        if ($request->owner_id) {
            $query->whereHas('booking', fn($q) =>
                $q->whereHas('hotel', fn($q2) => $q2->where('owner_id', $request->owner_id))
            );
        }

        $payments = $query->get()->filter(fn($p) => $p->booking); // buang payment tanpa booking

        // Laba komisi per booking. Pakai field skema-beban (owner_payout, commission_profit)
        // bila ada; fallback ke rumus lama untuk booking historis (kolom null).
        $rows = $payments->map(function ($p) use ($pphRate) {
            $b      = $p->booking;
            $base   = (float) $b->base_price;
            $markup = (float) $b->markup_amount;
            $pph    = round($base * $pphRate, 2);

            // Laba ArahInn: commission_profit (bisa minus karena nalangin promo ArahInn)
            $profit = $b->commission_profit !== null
                ? round((float) $b->commission_profit, 2)
                : round(max(0, $markup - $pph), 2);
            // Pendapatan owner (netto setelah beban diskonnya sendiri)
            $ownerRev = $b->owner_payout !== null ? round((float) $b->owner_payout, 2) : round($base, 2);
            $pct      = $ownerRev > 0 ? round($profit / $ownerRev * 100, 1) : 0;
            $date     = ($p->paid_at ?? $p->created_at);
            return [
                'booking_id'        => $b->id,
                'booking_code'      => $b->booking_code,
                'guest_name'        => $b->guest_name,
                'hotel_id'          => $b->hotel_id,
                'hotel_name'        => $b->hotel?->name,
                'hotel_city'        => $b->hotel?->city,
                'date'              => $date?->format('Y-m-d'),
                'base_price'        => $ownerRev,        // = pendapatan owner (kompat field lama)
                'markup_amount'     => round($markup, 2),
                'pph_amount'        => $pph,
                'discount_arahinn'  => (float) ($b->discount_arahinn ?? 0),
                'discount_owner'    => (float) ($b->discount_owner ?? 0),
                'commission_profit' => $profit,
                'commission_pct'    => $pct,
                'total_price'       => (float) $b->total_price,
            ];
        })->values();

        $totalProfit  = round($rows->sum('commission_profit'), 2);
        $totalBase    = round($rows->sum('base_price'), 2);
        $totalMarkup  = round($rows->sum('markup_amount'), 2);
        $totalPph     = round($rows->sum('pph_amount'), 2);
        $totalGross   = round($rows->sum('total_price'), 2);
        $count        = $rows->count();

        // Breakdown harian
        $daily = $rows->groupBy('date')
            ->map(fn($g, $date) => [
                'date'   => $date,
                'count'  => $g->count(),
                'profit' => round($g->sum('commission_profit'), 2),
                'base'   => round($g->sum('base_price'), 2),
            ])
            ->values();

        // Breakdown per hotel (top kontributor laba)
        $byHotel = $rows->groupBy('hotel_id')
            ->map(fn($g) => [
                'hotel_id'   => $g->first()['hotel_id'],
                'hotel_name' => $g->first()['hotel_name'],
                'count'      => $g->count(),
                'profit'     => round($g->sum('commission_profit'), 2),
            ])
            ->sortByDesc('profit')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total_profit'      => $totalProfit,   // laba komisi platform
                'total_base'        => $totalBase,     // porsi owner (harga kamar)
                'total_markup'      => $totalMarkup,   // komisi + PPh
                'total_pph'         => $totalPph,      // PPh (disetor, bukan laba)
                'total_gross'       => $totalGross,    // total yang dibayar customer
                'booking_count'     => $count,
                'avg_commission_pct'=> $totalBase > 0 ? round($totalProfit / $totalBase * 100, 1) : 0,
                'period'            => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'daily'             => $daily,
                'by_hotel'          => $byHotel,
                'items'             => $rows,
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
