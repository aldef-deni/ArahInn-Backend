<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\LoyaltyPoint;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// =====================================================
// ROOM LOCK SERVICE
// Uses database cache instead of Redis
// =====================================================
class RoomLockService
{
    private int $lockSeconds;

    public function __construct()
    {
        // Mutex PENDEK (detik) — hanya cegah 2 request bersamaan double-book unit
        // terakhir. BUKAN hold 30 menit. Ketersediaan per-tanggal dijaga oleh
        // assertAllotment (yang sudah menghitung booking pending + paid per tanggal).
        $this->lockSeconds = (int) config('ota.booking_lock_seconds', 20);
    }

    public function lock(int $roomId, string $bookingCode): bool
    {
        $lockKey = "room_lock:{$roomId}";

        // Atomic check-and-set: add() returns false if key exists
        $result = Cache::add($lockKey, $bookingCode, now()->addSeconds($this->lockSeconds));

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
        // Mutex pendek: kunci room sebentar supaya cek ketersediaan + simpan booking
        // bersifat atomic (cegah 2 request bersamaan double-book unit terakhir).
        // Dilepas SELALU di finally — tidak nyangkut, tidak memblokir user sendiri.
        $bookingCode = Booking::generateCode();
        $this->lock->lock((int) $data['room_id'], $bookingCode);

        try {
            // 0. Cek allotment per tanggal (sudah menghitung booking pending + paid)
            $this->assertAllotment(
                (int) $data['room_id'],
                $data['check_in'],
                $data['check_out'],
                (int) ($data['room_count'] ?? 1)
            );

            // 1. Hitung harga
            $priceData = $this->pricing->calculate([
                'room_id'    => $data['room_id'],
                'check_in'   => $data['check_in'],
                'check_out'  => $data['check_out'],
                'promo_code' => $data['promo_code'] ?? null,
                'user_id'    => $userId,
                'use_points' => $data['use_points'] ?? false,
                'points_to_redeem' => $data['points_to_redeem'] ?? null,
                'room_count' => $data['room_count'] ?? 1,
            ]);

            // 3. Simpan ke database (transaksi)
            $booking = DB::transaction(function () use ($data, $userId, $priceData, $bookingCode) {
                // Batas waktu pembayaran: mode MANUAL ikut setting `expires_hours`
                // (Pengaturan superadmin); selain itu (DOKU VA) tetap 30 menit.
                $payMode   = \App\Http\Controllers\Admin\SettingController::paymentMode();
                $expiresAt = $payMode === 'manual'
                    ? now()->addHours(max(1, (int) (\App\Http\Controllers\Admin\SettingController::manualBank()['expires_hours'] ?? 24)))
                    : now()->addMinutes(30);

                $booking = Booking::create([
                    'booking_code'     => $bookingCode,
                    'user_id'          => $userId,
                    'hotel_id'         => $data['hotel_id'],
                    'room_id'          => $data['room_id'],
                    'rate_plan_id'     => $priceData['rate_plan']['id'] ?? null,
                    'check_in'         => $data['check_in'],
                    'check_out'        => $data['check_out'],
                    'total_nights'     => $priceData['nights'],
                    'guests'           => $data['guests'],
                    'room_count'       => $data['room_count'] ?? 1,
                    'base_price'       => $priceData['base_price'],
                    'markup_amount'    => $priceData['markup_amount'],
                    'promo_discount'   => $priceData['promo_discount'],
                    'discount_arahinn' => $priceData['discount_arahinn'] ?? 0,
                    'discount_owner'   => $priceData['discount_owner'] ?? 0,
                    'owner_payout'     => $priceData['owner_payout'] ?? null,
                    'commission_profit'=> $priceData['commission_profit'] ?? null,
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
                    'expires_at'       => $expiresAt,
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

            // 4. Kirim email konfirmasi ke tamu
            $booking->load(['hotel', 'room']);
            try {
                Mail::to($booking->guest_email)->send(new \App\Mail\BookingConfirmationMail($booking));
            } catch (\Throwable) {}

            // 5. Kirim notifikasi reservasi baru ke owner hotel
            try {
                $ownerEmail = \App\Models\User::find($booking->hotel?->owner_id)?->email;
                if ($ownerEmail) {
                    Mail::to($ownerEmail)->send(new \App\Mail\NewReservationMail($booking));
                }
            } catch (\Throwable) {}

            return ['booking' => $booking, 'pricing' => $priceData];

        } finally {
            // Selalu lepas mutex (sukses/gagal) → kamar tidak ter-lock berlama-lama
            // & user tidak terblokir oleh lock-nya sendiri saat retry.
            $this->lock->unlock((int) $data['room_id']);
        }
    }

    /**
     * Cek allotment per tanggal: pastikan jumlah kamar yang sudah ter-book
     * + room_count yang diminta tidak melebihi available_units (atau total_units
     * sebagai fallback) untuk setiap tanggal stay.
     */
    private function assertAllotment(int $roomId, string $checkIn, string $checkOut, int $roomCount = 1): void
    {
        $room = Room::find($roomId);
        if (!$room) {
            throw new \InvalidArgumentException('Kamar tidak ditemukan.');
        }

        $totalUnits = (int) $room->total_units;
        if ($roomCount < 1) $roomCount = 1;

        $cursor = \Carbon\Carbon::parse($checkIn);
        $end    = \Carbon\Carbon::parse($checkOut);

        while ($cursor->lt($end)) {
            $dateStr = $cursor->format('Y-m-d');

            $price = \App\Models\RoomPrice::where('room_id', $roomId)
                ->whereDate('date', $dateStr)
                ->first();

            $allotment = ($price && $price->available_units !== null)
                ? (int) $price->available_units
                : $totalUnits;

            // Eksplisit ditutup
            if ($price && $price->is_available === false) {
                throw new \InvalidArgumentException(
                    "Kamar tidak tersedia untuk tanggal {$dateStr}."
                );
            }

            if ($allotment <= 0) {
                throw new \InvalidArgumentException(
                    "Kamar sudah habis dipesan untuk tanggal {$dateStr}."
                );
            }

            // Hitung booking aktif yang menempati tanggal ini.
            // Pending yang sudah expired (lewat expires_at) tidak dihitung
            // supaya tidak nge-block customer baru.
            $booked = Booking::where('room_id', $roomId)
                ->where(function ($q) {
                    $q->whereIn('status', ['paid', 'issued'])
                      ->orWhere(function ($qq) {
                          $qq->where('status', 'pending')
                             ->where(function ($qqq) {
                                 $qqq->whereNull('expires_at')
                                     ->orWhere('expires_at', '>', now());
                             });
                      });
                })
                ->where('check_in',  '<=', $dateStr)
                ->where('check_out', '>',  $dateStr)
                ->sum('room_count');

            if (($booked + $roomCount) > $allotment) {
                $sisa = max(0, $allotment - (int) $booked);
                throw new \InvalidArgumentException(
                    "Sisa kamar pada {$dateStr} hanya {$sisa}. Permintaan {$roomCount} kamar melebihi kapasitas."
                );
            }

            $cursor->addDay();
        }
    }

    public function issue(Booking $booking): Booking
    {
        // Idempotent — kalau sudah issued, skip semua. Resend voucher via
        // endpoint resendVoucher (atau Console::resend-voucher) tetap bisa.
        if ($booking->status === 'issued' && $booking->issued_at) {
            logger()->info('BookingIssued: skipped (already issued)', [
                'booking_code' => $booking->booking_code,
            ]);
            return $booking;
        }

        $booking->update(['status' => 'issued', 'issued_at' => now()]);
        $this->lock->unlock($booking->room_id);

        // Tambah loyalty points sesuai skema tier (Rp earn_per per poin × multiplier)
        $this->loyalty->earnForBooking($booking);

        // Refresh model + relations untuk pastikan data lengkap saat render PDF
        $booking = $booking->fresh(['hotel', 'room']) ?? $booking;

        // Kirim e-voucher ke tamu (wrap try-catch agar gagal mail tidak hentikan flow)
        try {
            if (!$booking->guest_email) {
                logger()->warning('BookingIssued: guest_email kosong, skip kirim ke tamu', [
                    'booking_code' => $booking->booking_code,
                ]);
            } else {
                Mail::to($booking->guest_email)->send(new \App\Mail\BookingIssuedMail($booking));
                // Tandai voucher BERHASIL terkirim (untuk penanda di panel admin)
                $booking->update(['voucher_sent_at' => now(), 'voucher_error' => null]);
                logger()->info('BookingIssued: voucher sent to guest', [
                    'booking_code' => $booking->booking_code,
                    'guest_email'  => $booking->guest_email,
                ]);
            }
        } catch (\Throwable $e) {
            // Simpan error agar admin tahu voucher GAGAL terkirim + bisa kirim ulang
            $booking->update(['voucher_error' => mb_substr($e->getMessage(), 0, 480)]);
            logger()->error('BookingIssued: failed to send voucher to guest', [
                'booking_code' => $booking->booking_code,
                'guest_email'  => $booking->guest_email,
                'error'        => $e->getMessage(),
                'trace_first'  => explode("\n", $e->getTraceAsString())[0] ?? null,
            ]);
        }

        // Kirim copy e-voucher ke owner properti
        try {
            $ownerId    = $booking->hotel?->owner_id;
            $ownerEmail = $ownerId ? \App\Models\User::find($ownerId)?->email : null;

            logger()->info('BookingIssued: sending to owner', [
                'booking_code' => $booking->booking_code,
                'hotel_id'     => $booking->hotel?->id,
                'owner_id'     => $ownerId,
                'owner_email'  => $ownerEmail,
            ]);

            if ($ownerEmail) {
                Mail::to($ownerEmail)->send(new \App\Mail\BookingIssuedMail($booking));
            } else {
                logger()->warning('BookingIssued: owner email missing', [
                    'booking_code' => $booking->booking_code,
                    'hotel_id'     => $booking->hotel?->id,
                    'owner_id'     => $ownerId,
                ]);
            }
        } catch (\Throwable $e) {
            logger()->error('BookingIssued: failed to send to owner', [
                'booking_code' => $booking->booking_code,
                'error'        => $e->getMessage(),
            ]);
        }

        // Kirim juga ke email tambahan yang didaftarkan owner di Step 3 form daftar hotel
        $extraEmails = is_array($booking->hotel?->voucher_emails) ? $booking->hotel->voucher_emails : [];
        foreach ($extraEmails as $extraEmail) {
            try {
                Mail::to($extraEmail)->send(new \App\Mail\BookingIssuedMail($booking));
                logger()->info('BookingIssued: voucher sent to extra email', [
                    'booking_code' => $booking->booking_code,
                    'extra_email'  => $extraEmail,
                ]);
            } catch (\Throwable $e) {
                logger()->error('BookingIssued: failed to send to extra email', [
                    'booking_code' => $booking->booking_code,
                    'extra_email'  => $extraEmail,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        // Notify guest: payment confirmed
        NotificationService::send(
            $booking->user_id, 'booking_paid',
            'Pembayaran Dikonfirmasi',
            "Booking #{$booking->booking_code} telah dikonfirmasi. Selamat menikmati perjalanan!",
            ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]
        );

        return $booking->fresh(['hotel', 'room']);
    }

    public function cancel(Booking $booking): Booking
    {
        $booking->load(['hotel', 'room']);
        $booking->update(['status' => 'canceled', 'canceled_at' => now()]);
        $this->lock->unlock($booking->room_id);

        // Email ke tamu
        Mail::to($booking->guest_email)->send(new \App\Mail\BookingCanceledMail($booking));

        // Email ke owner hotel
        try {
            $ownerEmail = \App\Models\User::find($booking->hotel?->owner_id)?->email;
            if ($ownerEmail) {
                Mail::to($ownerEmail)->send(new \App\Mail\BookingCanceledOwnerMail($booking));
            }
        } catch (\Throwable) {}

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
