<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\LoyaltyPoint;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// =====================================================
// ROOM LOCK SERVICE
// Uses database cache instead of Redis
// =====================================================
class RoomLockService
{
    private int $lockMinutes;

    public function __construct()
    {
        $this->lockMinutes = (int) config('ota.booking_expiry_minutes', 30);
    }

    public function lock(int $roomId, string $bookingCode): bool
    {
        $lockKey = "room_lock:{$roomId}";

        // Atomic check-and-set: add() returns false if key exists
        $result = Cache::add($lockKey, $bookingCode, now()->addMinutes($this->lockMinutes));

        if (!$result) {
            throw new \RuntimeException(
                'Kamar sedang dalam proses pemesanan oleh pengguna lain. Coba lagi dalam beberapa menit.'
            );
        }
        return true;
    }

    public function unlock(int $roomId): void
    {
        Cache::forget("room_lock:{$roomId}");
    }

    public function isLocked(int $roomId): bool
    {
        return Cache::has("room_lock:{$roomId}");
    }

    public function ttl(int $roomId): int
    {
        // Returns remaining TTL in seconds (approximate via Cache)
        return 0; // simplified
    }
}

// =====================================================
// BOOKING SERVICE
// =====================================================
class BookingService
{
    public function __construct(
        private PricingService  $pricing,
        private RoomLockService $lock,
        private LoyaltyService  $loyalty,
    ) {}

    public function create(array $data, int $userId): array
    {
        // 1. Hitung harga
        $priceData = $this->pricing->calculate([
            'room_id'    => $data['room_id'],
            'check_in'   => $data['check_in'],
            'check_out'  => $data['check_out'],
            'promo_code' => $data['promo_code'] ?? null,
            'user_id'    => $userId,
            'use_points' => $data['use_points'] ?? false,
        ]);

        // 2. Lock kamar
        $bookingCode = Booking::generateCode();
        $this->lock->lock($data['room_id'], $bookingCode);

        try {
            // 3. Simpan ke database (transaksi)
            $booking = DB::transaction(function () use ($data, $userId, $priceData, $bookingCode) {
                $booking = Booking::create([
                    'booking_code'     => $bookingCode,
                    'user_id'          => $userId,
                    'hotel_id'         => $data['hotel_id'],
                    'room_id'          => $data['room_id'],
                    'check_in'         => $data['check_in'],
                    'check_out'        => $data['check_out'],
                    'total_nights'     => $priceData['nights'],
                    'guests'           => $data['guests'],
                    'base_price'       => $priceData['base_price'],
                    'markup_amount'    => $priceData['markup_amount'],
                    'promo_discount'   => $priceData['promo_discount'],
                    'loyalty_discount' => $priceData['loyalty_discount'],
                    'tax_amount'       => $priceData['tax_amount'],
                    'total_price'      => $priceData['total_price'],
                    'price_suffix'     => $priceData['price_suffix'],
                    'status'           => 'pending',
                    'promo_id'         => $priceData['promo']?->id,
                    'voucher_code'     => $data['promo_code'] ?? null,
                    'guest_name'       => $data['guest_name'],
                    'guest_email'      => $data['guest_email'],
                    'guest_phone'      => $data['guest_phone'] ?? null,
                    'notes'            => $data['notes'] ?? null,
                    'expires_at'       => now()->addMinutes(30),
                ]);

                // Update promo used_count
                if ($priceData['promo']) {
                    $priceData['promo']->increment('used_count');
                }

                // Kurangi loyalty points jika dipakai
                if ($priceData['loyalty_discount'] > 0) {
                    $this->loyalty->redeem($booking->user_id, (int)$priceData['loyalty_discount'], $booking->id);
                }

                return $booking;
            });

            // 4. Kirim email konfirmasi ke tamu (queued)
            $booking->load(['hotel', 'room']);
            try {
                Mail::to($booking->guest_email)->queue(new \App\Mail\BookingConfirmationMail($booking));
            } catch (\Throwable) {}

            // 5. Kirim notifikasi reservasi baru ke owner hotel (queued)
            try {
                $ownerEmail = \App\Models\User::find($booking->hotel?->owner_id)?->email;
                if ($ownerEmail) {
                    Mail::to($ownerEmail)->queue(new \App\Mail\NewReservationMail($booking));
                }
            } catch (\Throwable) {}

            return ['booking' => $booking, 'pricing' => $priceData];

        } catch (\Exception $e) {
            $this->lock->unlock($data['room_id']);
            throw $e;
        }
    }

    public function issue(Booking $booking): Booking
    {
        $booking->update(['status' => 'issued', 'issued_at' => now()]);
        $this->lock->unlock($booking->room_id);

        // Tambah loyalty points: 1 point per Rp 1.000
        $points = (int) floor($booking->total_price / 1000);
        if ($points > 0) {
            $this->loyalty->earn($booking->user_id, $points, $booking->id);
        }

        Mail::to($booking->guest_email)->queue(new \App\Mail\BookingIssuedMail($booking));

        return $booking->fresh(['hotel', 'room']);
    }

    public function cancel(Booking $booking): Booking
    {
        $booking->update(['status' => 'canceled', 'canceled_at' => now()]);
        $this->lock->unlock($booking->room_id);
        Mail::to($booking->guest_email)->queue(new \App\Mail\BookingCanceledMail($booking));
        return $booking;
    }
}

// =====================================================
// ACTIVITY LOG SERVICE
// =====================================================
class ActivityLogService
{
    public static function log(
        ?int    $userId,
        string  $action,
        ?string $entity   = null,
        mixed   $entityId = null,
        ?object $request  = null,
        ?array  $payload  = null
    ): void {
        try {
            \App\Models\ActivityLog::create([
                'user_id'    => $userId,
                'action'     => $action,
                'entity'     => $entity,
                'entity_id'  => $entityId,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'payload'    => $payload,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Don't throw — logging must not break main flow
            logger()->error('ActivityLog failed: ' . $e->getMessage());
        }
    }
}
