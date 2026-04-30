<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\Booking;
use App\Models\Payment;
use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\CoreApi;
use Midtrans\Notification;

// =====================================================
// LOYALTY SERVICE
// =====================================================
class LoyaltyService
{
    public function earn(int $userId, int $points, int $bookingId): void
    {
        LoyaltyPoint::create([
            'user_id'     => $userId,
            'points'      => $points,
            'type'        => 'earn',
            'description' => 'Poin dari booking',
            'booking_id'  => $bookingId,
            'expires_at'  => now()->addYear(),
            'created_at'  => now(),
        ]);
    }

    public function redeem(int $userId, int $amount, int $bookingId): void
    {
        LoyaltyPoint::create([
            'user_id'     => $userId,
            'points'      => -$amount,
            'type'        => 'redeem',
            'description' => 'Poin digunakan untuk pembayaran',
            'booking_id'  => $bookingId,
            'created_at'  => now(),
        ]);
    }

    public function getBalance(int $userId): int
    {
        return (int) LoyaltyPoint::where('user_id', $userId)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->sum('points');
    }

    public function getHistory(int $userId, int $page = 1, int $limit = 20)
    {
        return LoyaltyPoint::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate($limit, ['*'], 'page', $page);
    }
}

// =====================================================
// PAYMENT SERVICE (Midtrans)
// =====================================================
class PaymentService
{
    public function __construct()
    {
        Config::$serverKey    = config('services.midtrans.server_key');
        Config::$clientKey    = config('services.midtrans.client_key');
        Config::$isProduction = config('services.midtrans.is_production', false);
        Config::$isSanitized  = true;
        Config::$is3ds        = true;
    }

    /**
     * Buat transaksi Midtrans Bank Transfer / VA
     */
    public function initiate(Booking $booking, string $paymentMethod): array
    {
        if (!in_array($booking->status, ['pending'])) {
            throw new \InvalidArgumentException('Booking tidak dalam status pending.');
        }

        $params = [
            'transaction_details' => [
                'order_id'    => $booking->booking_code,
                'gross_amount'=> (int) $booking->total_price,
            ],
            'item_details' => [[
                'id'       => $booking->room_id,
                'price'    => (int) $booking->total_price,
                'quantity' => 1,
                'name'     => ($booking->hotel->name ?? 'Hotel') . ' - ' . ($booking->room->name ?? 'Kamar'),
            ]],
            'customer_details' => [
                'first_name' => $booking->guest_name,
                'email'      => $booking->guest_email,
                'phone'      => $booking->guest_phone,
            ],
        ];

        // Payment method specific
        if (in_array($paymentMethod, ['bca', 'bni', 'bri', 'permata'])) {
            $params['payment_type']  = 'bank_transfer';
            $params['bank_transfer'] = ['bank' => $paymentMethod];
        } elseif ($paymentMethod === 'gopay') {
            $params['payment_type'] = 'gopay';
        } elseif ($paymentMethod === 'qris') {
            $params['payment_type'] = 'qris';
        } else {
            $params['payment_type']  = 'bank_transfer';
            $params['bank_transfer'] = ['bank' => 'bca'];
        }

        try {
            $response = CoreApi::charge($params);
        } catch (\Exception $e) {
            throw new \RuntimeException('Gagal membuat transaksi: ' . $e->getMessage());
        }

        // Simpan payment record
        $payment = Payment::create([
            'booking_id'     => $booking->id,
            'amount'         => $booking->total_price,
            'method'         => $paymentMethod,
            'gateway'        => 'midtrans',
            'gateway_trx_id' => $response->transaction_id ?? null,
            'status'         => 'pending',
            'expired_at'     => now()->addHour(),
            'payload'        => (array) $response,
        ]);

        // Extract VA number
        $vaNumber = $response->va_numbers[0]->va_number
            ?? $response->payment_code
            ?? null;

        return [
            'payment'         => $payment,
            'va_number'       => $vaNumber,
            'gateway_response'=> $response,
        ];
    }

    /**
     * Handle Midtrans webhook notification
     */
    public function handleWebhook(array $payload): void
    {
        try {
            $notification = new Notification();
        } catch (\Exception $e) {
            throw new \RuntimeException('Invalid webhook: ' . $e->getMessage());
        }

        $orderId   = $notification->order_id;
        $txStatus  = $notification->transaction_status;
        $fraudStatus = $notification->fraud_status ?? 'accept';

        $booking = Booking::where('booking_code', $orderId)->first();
        if (!$booking) return;

        if (in_array($txStatus, ['capture', 'settlement']) && $fraudStatus === 'accept') {
            // Payment success
            Payment::where('booking_id', $booking->id)
                   ->where('gateway', 'midtrans')
                   ->update([
                       'status'  => 'settlement',
                       'paid_at' => now(),
                       'payload' => (array)$notification,
                   ]);

            $booking->update(['status' => 'paid']);

            // Issue booking
            app(BookingService::class)->issue($booking);

        } elseif (in_array($txStatus, ['cancel', 'deny', 'expire'])) {
            Payment::where('booking_id', $booking->id)
                   ->where('gateway', 'midtrans')
                   ->update(['status' => $txStatus === 'expire' ? 'expired' : 'failed']);

            $booking->update(['status' => 'canceled']);
        }
    }
}
