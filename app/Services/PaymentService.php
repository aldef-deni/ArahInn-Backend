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
    public function __construct(
        private DokuService $doku,
        private DokuMerchantPicker $merchantPicker,
    ) {}

    /**
     * Generate DOKU virtual account for the booking.
     * $paymentMethod = bank slug: 'bca' | 'mandiri' | 'bni' | 'bri' | 'permata'
     *
     * Merchant DOKU dipilih otomatis oleh DokuMerchantPicker.
     * Sekali dipilih, merchant disimpan di kolom payments.merchant_key
     * supaya webhook bisa diverifikasi dengan kunci yang sama.
     */
    public function initiate(Booking $booking, string $paymentMethod): array
    {
        if ($booking->status !== 'pending') {
            throw new \InvalidArgumentException('Booking tidak dalam status pending.');
        }

        // Routing berdasarkan mode payment di config/services.php
        // mode='manual' → manual bank transfer (sementara, sebelum izin company beres)
        // mode='doku'   → DOKU SNAP BI VA (default production)
        $mode = \App\Http\Controllers\Admin\SettingController::paymentMode();
        if ($mode === 'manual') {
            return $this->initiateManualTransfer($booking);
        }

        // Cek pending VA aktif untuk booking ini
        $existing = Payment::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->where('gateway', 'doku')
            ->where('expired_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            // Kalau method SAMA → reuse VA (prevent "Inconsistent Request" dari DOKU
            // saat user re-tap tombol "Bayar via VA X" pada bank yang sama)
            if ($existing->method === $paymentMethod) {
                $vaNumber = trim($existing->payload['_va_number'] ?? '');
                return [
                    'payment'      => $existing,
                    'va_number'    => $vaNumber,
                    'bank'         => strtoupper($existing->method),
                    'merchant_key' => $existing->merchant_key,
                    'expired_at'   => $existing->expired_at->toIso8601String(),
                ];
            }

            // Method BEDA → user pindah bank (mis. BCA → Mandiri).
            // Cancel pending VA lama supaya tidak ada 2 VA aktif untuk 1 booking,
            // lalu lanjut create VA baru untuk method yang diminta.
            $existing->update(['status' => 'canceled']);
            logger()->info('Payment: existing pending VA canceled (method changed)', [
                'booking_code' => $booking->booking_code,
                'old_method'   => $existing->method,
                'new_method'   => $paymentMethod,
            ]);
        }

        // Pilih merchant secara round-robin
        $merchantKey = $this->merchantPicker->pick();
        $this->doku->useMerchant($merchantKey);

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
            'merchant_key'   => $merchantKey,
            'gateway_trx_id' => $result['trxId'] ?? $booking->booking_code,
            'status'         => 'pending',
            'expired_at'     => $expiresAt,
            'payload'        => $result,
        ]);

        // Notif: VA sudah dibuat, customer perlu bayar sebelum expired
        NotificationService::send(
            $booking->user_id,
            'payment_pending',
            'Selesaikan pembayaran ' . strtoupper($paymentMethod),
            'Nomor VA: ' . ($vaNumber ?: '-') . ' · Bayar sebelum ' . $expiresAt->format('H:i, d M Y'),
            [
                'booking_id'   => $booking->id,
                'booking_code' => $booking->booking_code,
                'payment_id'   => $payment->id,
                'bank'         => strtoupper($paymentMethod),
                'va_number'    => $vaNumber,
                'expired_at'   => $expiresAt->toIso8601String(),
            ]
        );

        return [
            'payment'      => $payment,
            'va_number'    => $vaNumber,
            'bank'         => strtoupper($paymentMethod),
            'merchant_key' => $merchantKey,
            'expired_at'   => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Handle DOKU SNAP webhook notification.
     * Identifikasi merchant dari payment yang sebelumnya dibuat,
     * lalu verify signature pakai kunci merchant tersebut.
     */
    public function handleWebhook(string $rawBody, array $headers, string $webhookPath): void
    {
        $payload     = json_decode($rawBody, true) ?? [];
        $bookingCode = $payload['trxId'] ?? null;
        if (!$bookingCode) return;

        $booking = Booking::where('booking_code', $bookingCode)->first();
        if (!$booking) return;

        // Cari merchant_key dari payment terkait booking ini
        $payment = Payment::where('booking_id', $booking->id)
            ->where('gateway', 'doku')
            ->latest()
            ->first();

        $merchantKey = $payment?->merchant_key ?? 'default';
        $this->doku->useMerchant($merchantKey);

        if (!$this->doku->verifyWebhook($headers, $rawBody, $webhookPath)) {
            throw new \RuntimeException('Invalid DOKU webhook signature (merchant: ' . $merchantKey . ').');
        }

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

    // =====================================================
    // MANUAL BANK TRANSFER MODE (sementara, sebelum izin company beres)
    // =====================================================

    /**
     * Buat instruksi pembayaran manual transfer.
     * Generate nominal unik (base + kode 3 digit) supaya admin gampang
     * cocokkan mutasi rekening dengan booking spesifik.
     *
     * Contoh: total Rp 500.000 → customer transfer Rp 500.{xxx} (xxx = booking_id mod 1000).
     */
    public function initiateManualTransfer(Booking $booking): array
    {
        // Reuse pending manual payment kalau sudah ada (tidak expired)
        $existing = Payment::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->where('gateway', 'manual')
            ->where('expired_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            return $this->buildManualResponse($booking, $existing);
        }

        // Cancel pending DOKU lama (kalau ada dari mode sebelumnya)
        Payment::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->update(['status' => 'canceled']);

        $bank        = \App\Http\Controllers\Admin\SettingController::manualBank();
        $expiresAt   = now()->addHours((int) ($bank['expires_hours'] ?? 24));

        // Kode unik 3 digit dari booking_id supaya nominal selalu unique per booking
        // Total final = nominal_dasar + kode_unik (mis. 500000 + 458 = 500458)
        $uniqueCode    = (int) ($booking->id % 1000);
        $baseAmount    = (float) $booking->total_price;
        $finalAmount   = $baseAmount + $uniqueCode;

        $payment = Payment::create([
            'booking_id'     => $booking->id,
            'amount'         => $finalAmount,
            'method'         => 'bank_transfer',
            'gateway'        => 'manual',
            'merchant_key'   => 'manual',
            'gateway_trx_id' => $booking->booking_code,
            'status'         => 'pending',
            'expired_at'     => $expiresAt,
            'payload'        => [
                'mode'           => 'manual',
                'bank_name'      => $bank['bank_name'],
                'account_number' => $bank['account_number'],
                'account_name'   => $bank['account_name'],
                'base_amount'    => $baseAmount,
                'unique_code'    => $uniqueCode,
                'final_amount'   => $finalAmount,
            ],
        ]);

        NotificationService::send(
            $booking->user_id,
            'payment_pending',
            'Selesaikan pembayaran transfer',
            'Transfer ' . number_format($finalAmount, 0, ',', '.') . ' ke ' . $bank['bank_name'] . ' ' . $bank['account_number'] . ' sebelum ' . $expiresAt->format('H:i, d M Y'),
            [
                'booking_id'   => $booking->id,
                'booking_code' => $booking->booking_code,
                'payment_id'   => $payment->id,
                'final_amount' => $finalAmount,
                'expired_at'   => $expiresAt->toIso8601String(),
            ]
        );

        return $this->buildManualResponse($booking, $payment);
    }

    private function buildManualResponse(Booking $booking, Payment $payment): array
    {
        $p = $payment->payload ?? [];
        return [
            'payment'        => $payment,
            'mode'           => 'manual',
            'bank_name'      => $p['bank_name']      ?? '',
            'account_number' => $p['account_number'] ?? '',
            'account_name'   => $p['account_name']   ?? '',
            'base_amount'    => (float) ($p['base_amount']   ?? 0),
            'unique_code'    => (int)   ($p['unique_code']   ?? 0),
            'final_amount'   => (float) ($p['final_amount']  ?? $payment->amount),
            'expired_at'     => $payment->expired_at?->toIso8601String(),
        ];
    }

    /**
     * Admin manual confirm: tandai payment manual sudah terverifikasi
     * (admin sudah cek mutasi rekening cocok), trigger booking issue.
     *
     * Idempotent: kalau sudah settlement, no-op.
     */
    public function confirmManualPayment(Booking $booking, int $adminId, ?string $notes = null): array
    {
        $payment = Payment::where('booking_id', $booking->id)
            ->where('gateway', 'manual')
            ->latest()
            ->first();

        if (!$payment) {
            throw new \RuntimeException('Tidak ada payment manual untuk booking ini.');
        }
        if ($payment->status === 'settlement') {
            return ['payment' => $payment, 'message' => 'Sudah dikonfirmasi sebelumnya.'];
        }

        $existingPayload = $payment->payload ?? [];
        $payment->update([
            'status'  => 'settlement',
            'paid_at' => now(),
            'payload' => array_merge($existingPayload, [
                'confirmed_by'    => $adminId,
                'confirmed_at'    => now()->toIso8601String(),
                'confirm_notes'   => $notes,
            ]),
        ]);

        if ($booking->status === 'pending') {
            $booking->update(['status' => 'paid']);
            // BookingService::booted event listener akan auto trigger issue()
        }

        return ['payment' => $payment->fresh(), 'booking' => $booking->fresh()];
    }

    /**
     * Customer upload bukti transfer (opsional, tapi membantu admin verify).
     * Simpan ke storage/app/public/uploads/payment-proofs/ + update payload.
     */
    public function attachProof(Booking $booking, string $storedPath): Payment
    {
        $payment = Payment::where('booking_id', $booking->id)
            ->where('gateway', 'manual')
            ->latest()
            ->firstOrFail();

        $payload = $payment->payload ?? [];
        $payload['proof_path']       = $storedPath;
        $payload['proof_uploaded_at']= now()->toIso8601String();
        $payment->update(['payload' => $payload]);

        // Notif admin
        NotificationService::sendToRoles(['superadmin', 'admin', 'finance'],
            'payment_proof_uploaded',
            'Bukti transfer baru',
            "Booking {$booking->booking_code} upload bukti transfer. Mohon verifikasi.",
            ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]
        );

        return $payment->fresh();
    }
}
