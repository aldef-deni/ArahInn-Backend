<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\Booking;
use App\Models\Payment;

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
// PAYMENT SERVICE (DOKU SNAP)
// =====================================================
class PaymentService
{
    public function __construct(private DokuService $doku) {}

    /**
     * Generate DOKU virtual account for the booking.
     * $paymentMethod = bank slug: 'bca' | 'mandiri' | 'bni' | 'bri' | 'permata'
     */
    public function initiate(Booking $booking, string $paymentMethod): array
    {
        if ($booking->status !== 'pending') {
            throw new \InvalidArgumentException('Booking tidak dalam status pending.');
        }

        // Return existing active VA instead of calling DOKU again (prevents "Inconsistent Request")
        $existing = Payment::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->where('gateway', 'doku')
            ->where('expired_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            $vaNumber = trim($existing->payload['_va_number'] ?? '');
            return [
                'payment'    => $existing,
                'va_number'  => $vaNumber,
                'bank'       => strtoupper($existing->method),
                'expired_at' => $existing->expired_at->toIso8601String(),
            ];
        }

        $expiresAt = now()->addHour();

        $result = $this->doku->createVirtualAccount(
            bank        : $paymentMethod,
            bookingCode : $booking->booking_code,
            bookingId   : $booking->id,
            amount      : (float) $booking->total_price,
            customer    : [
                'name'  => $booking->guest_name,
                'email' => $booking->guest_email,
                'phone' => $booking->guest_phone ?? '',
            ],
            expiresAt   : $expiresAt,
        );

        $vaNumber = trim(
            $result['virtualAccountData']['virtualAccountNo']
            ?? $result['virtualAccountNo']
            ?? $result['_va_number']
            ?? ''
        ) ?: null;

        $payment = Payment::create([
            'booking_id'     => $booking->id,
            'amount'         => $booking->total_price,
            'method'         => $paymentMethod,
            'gateway'        => 'doku',
            'gateway_trx_id' => $result['trxId'] ?? $booking->booking_code,
            'status'         => 'pending',
            'expired_at'     => $expiresAt,
            'payload'        => $result,
        ]);

        return [
            'payment'    => $payment,
            'va_number'  => $vaNumber,
            'bank'       => strtoupper($paymentMethod),
            'expired_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Handle DOKU SNAP webhook notification.
     * Pass the raw request body string and all request headers.
     */
    public function handleWebhook(string $rawBody, array $headers, string $webhookPath): void
    {
        if (!$this->doku->verifyWebhook($headers, $rawBody, $webhookPath)) {
            throw new \RuntimeException('Invalid DOKU webhook signature.');
        }

        $payload = json_decode($rawBody, true);

        // SNAP payment notification — trxId is our booking_code
        $bookingCode = $payload['trxId'] ?? null;
        if (!$bookingCode) return;

        $booking = Booking::where('booking_code', $bookingCode)->first();
        if (!$booking) return;

        // SNAP success: paidAmount present and equals totalAmount
        $paidAmount  = (float) ($payload['paidAmount']['value']  ?? 0);
        $totalAmount = (float) ($payload['totalAmount']['value'] ?? 0);
        $flagAdvise  = $payload['flagAdvise'] ?? 'N';

        if ($paidAmount >= $totalAmount && $flagAdvise === 'N') {
            Payment::where('booking_id', $booking->id)
                   ->where('gateway', 'doku')
                   ->update([
                       'status'  => 'settlement',
                       'paid_at' => now(),
                       'payload' => $payload,
                   ]);

            $booking->update(['status' => 'paid']);
            app(BookingService::class)->issue($booking);
        }
    }
}
