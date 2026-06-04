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
        $mode = \App\Http\Controllers\Admin\SettingController::paymentMode();

        // Untuk manual mode, payment_method tidak wajib (selalu bank_transfer)
        $rules = ['booking_id' => 'required|integer|exists:bookings,id'];
        if ($mode !== 'manual') {
            $rules['payment_method'] = 'required|string|in:bca,mandiri,bri,bsi';
        }
        $data = $request->validate($rules);

        try {
            $booking = Booking::with(['hotel', 'room'])->findOrFail($data['booking_id']);
            $method  = $data['payment_method'] ?? 'bank_transfer';
            $result  = $this->payment->initiate($booking, $method);
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Customer upload bukti transfer (manual mode).
     */
    public function uploadProof(Request $request, string $bookingId)
    {
        $request->validate([
            'proof' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $booking = Booking::findOrFail($bookingId);
        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $file     = $request->file('proof');
        $dir      = storage_path('app/public/uploads/payment-proofs');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'proof_' . $booking->booking_code . '_' . time() . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);
        $relPath  = 'uploads/payment-proofs/' . $filename;

        $payment = $this->payment->attachProof($booking, $relPath);

        return response()->json(['success' => true, 'data' => $payment]);
    }

    /**
     * Admin manual confirm: pembayaran transfer sudah masuk rekening.
     */
    public function confirmManual(Request $request, string $bookingId)
    {
        $request->validate(['notes' => 'nullable|string|max:500']);

        $booking = Booking::findOrFail($bookingId);
        try {
            $result = $this->payment->confirmManualPayment(
                $booking,
                $request->user()->id,
                $request->input('notes')
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Public: cek mode pembayaran aktif (untuk FE branching UI).
     */
    public function mode()
    {
        $mode = \App\Http\Controllers\Admin\SettingController::paymentMode();
        $data = ['mode' => $mode];
        if ($mode === 'manual') {
            $bank = \App\Http\Controllers\Admin\SettingController::manualBank();
            $data['bank'] = [
                'bank_name'      => $bank['bank_name'],
                'account_number' => $bank['account_number'],
                'account_name'   => $bank['account_name'],
            ];
        }
        return response()->json(['success' => true, 'data' => $data]);
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
