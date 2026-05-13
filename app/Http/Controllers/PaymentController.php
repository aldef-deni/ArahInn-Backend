<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payment) {}

    public function initiate(Request $request)
    {
        $data = $request->validate([
            'booking_id'     => 'required|integer|exists:bookings,id',
            'payment_method' => 'required|string|in:bca,mandiri,bni,bri,permata',
        ]);

        try {
            $booking = Booking::with(['hotel', 'room'])->findOrFail($data['booking_id']);
            $result  = $this->payment->initiate($booking, $data['payment_method']);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function webhookDoku(Request $request)
    {
        try {
            $this->payment->handleWebhook(
                $request->getContent(),
                $request->headers->all(),
                '/api/payments/webhook/doku',
            );
        } catch (\Exception $e) {
            logger()->error('DOKU webhook error: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    public function status(string $bookingId)
    {
        $payments = Payment::where('booking_id', $bookingId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $payments]);
    }

    public function index(Request $request)
    {
        $query = Payment::with(['booking:id,booking_code,guest_name,total_price,hotel_id']);

        if ($request->status) {
            $query->where('status', $request->status);
        }
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
}
