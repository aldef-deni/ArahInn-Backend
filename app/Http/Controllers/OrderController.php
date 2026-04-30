<?php

namespace App\Http\Controllers;

use App\Models\{Booking, Hotel};
use App\Services\{BookingService, ActivityLogService};
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private BookingService $bookingService) {}
    public function index(Request $request)
    {
        $user  = $request->user();
        $query = Booking::with(['user:id,name,email', 'hotel:id,name,city', 'room:id,name,type', 'payments:id,booking_id,status,method,paid_at,amount']);

        if ($user->hasRole('owner')) {
            $hotelIds = Hotel::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('hotel_id', $hotelIds);
        }

        if ($request->status)   $query->where('status', $request->status);
        if ($request->hotel_id) $query->where('hotel_id', $request->hotel_id);
        if ($request->from && $request->to) {
            $query->whereBetween('created_at', [$request->from, $request->to]);
        }

        $result = $query->orderBy('created_at', 'desc')->paginate($request->limit ?? 20);

        return response()->json([
            'success'    => true,
            'data'       => $result->items(),
            'pagination' => ['total' => $result->total(), 'page' => $result->currentPage()],
        ]);
    }

    public function updateStatus(Request $request, string $id)
    {
        $allowed = ['pending','paid','issued','canceled','refunded','rescheduled'];
        $data    = $request->validate(['status' => 'required|in:' . implode(',', $allowed)]);

        $booking = Booking::with(['hotel', 'room'])->findOrFail($id);

        if ($data['status'] === 'issued' && $booking->status !== 'issued') {
            $booking = $this->bookingService->issue($booking);
            ActivityLogService::log($request->user()->id, 'MANUAL_ISSUE_BOOKING', 'booking', $id, $request);
        } elseif ($data['status'] === 'canceled' && !in_array($booking->status, ['canceled','refunded'])) {
            $booking = $this->bookingService->cancel($booking);
            ActivityLogService::log($request->user()->id, 'MANUAL_CANCEL_BOOKING', 'booking', $id, $request);
        } else {
            $booking->update(['status' => $data['status']]);
            ActivityLogService::log($request->user()->id, 'UPDATE_BOOKING_STATUS', 'booking', $id, $request);
        }

        return response()->json(['success' => true, 'data' => $booking->fresh()]);
    }
}
