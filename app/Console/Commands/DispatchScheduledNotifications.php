<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\OtaNotification;
use App\Models\Payment;
use App\Models\Review;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Command terjadwal: jalankan via cron tiap 10 menit.
 *   * * * * * cd /path/to/app && php artisan arahinn:dispatch-notifications >> /dev/null 2>&1
 *
 * Menangani 4 jenis notifikasi terjadwal:
 *  1. payment_expired      → VA yang expired tapi belum dibayar
 *  2. booking_completed    → booking yang sudah lewat check_out (auto-complete)
 *  3. booking_reminder_checkin → H-1 sebelum check_in
 *  4. review_invitation    → H+1 sesudah check_out (jika belum review)
 */
class DispatchScheduledNotifications extends Command
{
    protected $signature = 'arahinn:dispatch-notifications';
    protected $description = 'Dispatch scheduled notifications (payment expired, booking completed, reminders, review invitation)';

    public function handle(): int
    {
        $this->info('Starting scheduled notifications dispatch...');

        $this->dispatchPaymentExpired();
        $this->dispatchBookingCompleted();
        $this->dispatchCheckinReminders();
        $this->dispatchReviewInvitations();

        $this->info('Done.');
        return self::SUCCESS;
    }

    /**
     * Notif untuk payment yang status=pending tapi expired_at sudah lewat
     * Idempoten: tandai payload['_notif_expired_sent'] = true sehingga tidak dobel.
     */
    private function dispatchPaymentExpired(): void
    {
        $expired = Payment::with('booking:id,booking_code,user_id')
            ->where('status', 'pending')
            ->where('expired_at', '<', now())
            ->whereDoesntHave('booking', fn($q) => $q->whereIn('status', ['paid', 'issued', 'canceled', 'refunded']))
            ->limit(50)
            ->get();

        foreach ($expired as $payment) {
            $payload = $payment->payload ?? [];
            if (!empty($payload['_notif_expired_sent'])) continue;

            $booking = $payment->booking;
            if (!$booking) continue;

            NotificationService::send(
                $booking->user_id,
                'payment_expired',
                'Batas waktu pembayaran terlewat',
                "VA " . strtoupper($payment->method) . " untuk booking #{$booking->booking_code} sudah kedaluwarsa. Silakan buat ulang pembayaran.",
                [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'payment_id'   => $payment->id,
                    'bank'         => strtoupper($payment->method),
                ]
            );

            $payment->update([
                'status'  => 'expired',
                'payload' => array_merge($payload, ['_notif_expired_sent' => true]),
            ]);
        }

        if ($expired->count()) {
            $this->info("Payment expired notifications: {$expired->count()}");
        }
    }

    /**
     * Booking sudah lewat check_out → notif "selesai" ke tamu & owner.
     * Tidak mengubah status (enum belum mendukung 'completed'). Idempoten via OtaNotification.
     */
    private function dispatchBookingCompleted(): void
    {
        $bookings = Booking::with('hotel:id,name,owner_id')
            ->whereIn('status', ['issued', 'rescheduled', 'paid'])
            ->whereDate('check_out', '<', today())
            ->limit(100)
            ->get();

        $sent = 0;
        foreach ($bookings as $booking) {
            if ($this->alreadySent($booking->user_id, 'booking_completed', $booking->id)) continue;

            NotificationService::send(
                $booking->user_id,
                'booking_completed',
                'Terima kasih telah menginap!',
                "Booking #{$booking->booking_code} di " . ($booking->hotel?->name ?? 'penginapan') . " sudah selesai. Bagaimana pengalaman Anda?",
                [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'hotel_id'     => $booking->hotel_id,
                ]
            );

            if ($ownerId = $booking->hotel?->owner_id) {
                if (!$this->alreadySent($ownerId, 'booking_completed', $booking->id)) {
                    NotificationService::send(
                        $ownerId,
                        'booking_completed',
                        'Tamu telah check-out',
                        "Booking #{$booking->booking_code} dari {$booking->guest_name} telah selesai.",
                        [
                            'booking_id'   => $booking->id,
                            'booking_code' => $booking->booking_code,
                        ]
                    );
                }
            }
            $sent++;
        }

        if ($sent) {
            $this->info("Booking-completed notifications: {$sent}");
        }
    }

    /**
     * Reminder H-1 check-in untuk customer & owner.
     * Idempoten via OtaNotification (tidak send dobel pada hari yang sama).
     */
    private function dispatchCheckinReminders(): void
    {
        $tomorrow = today()->addDay();

        $bookings = Booking::with('hotel:id,name,owner_id,address,city')
            ->whereIn('status', ['paid', 'issued', 'rescheduled'])
            ->whereDate('check_in', $tomorrow)
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($bookings as $booking) {
            if ($this->alreadySent($booking->user_id, 'booking_reminder_checkin', $booking->id)) continue;

            $hotelName = $booking->hotel?->name ?? 'penginapan';
            NotificationService::send(
                $booking->user_id,
                'booking_reminder_checkin',
                'Check-in besok!',
                "Jangan lupa, besok Anda check-in di {$hotelName}. Booking #{$booking->booking_code}.",
                [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'hotel_id'     => $booking->hotel_id,
                    'check_in'     => $booking->check_in?->toDateString(),
                ]
            );

            if ($ownerId = $booking->hotel?->owner_id) {
                if (!$this->alreadySent($ownerId, 'booking_reminder_checkin', $booking->id)) {
                    NotificationService::send(
                        $ownerId,
                        'booking_reminder_checkin',
                        'Tamu check-in besok',
                        "{$booking->guest_name} akan check-in besok di {$hotelName}. Booking #{$booking->booking_code}.",
                        [
                            'booking_id'   => $booking->id,
                            'booking_code' => $booking->booking_code,
                            'guest_name'   => $booking->guest_name,
                        ]
                    );
                }
            }
            $sent++;
        }

        if ($sent) $this->info("Check-in reminders sent: {$sent}");
    }

    /**
     * Ajak review H+1 sesudah check_out untuk customer (jika belum review).
     */
    private function dispatchReviewInvitations(): void
    {
        $yesterday = today()->subDay();

        $bookings = Booking::with('hotel:id,name')
            ->whereIn('status', ['completed', 'issued', 'rescheduled'])
            ->whereDate('check_out', $yesterday)
            ->limit(200)
            ->get();

        $sent = 0;
        foreach ($bookings as $booking) {
            // Skip kalau sudah pernah review booking ini
            $hasReviewed = Review::where('booking_id', $booking->id)
                ->where('user_id', $booking->user_id)
                ->exists();
            if ($hasReviewed) continue;

            if ($this->alreadySent($booking->user_id, 'review_invitation', $booking->id)) continue;

            $hotelName = $booking->hotel?->name ?? 'penginapan';
            NotificationService::send(
                $booking->user_id,
                'review_invitation',
                'Bagaimana pengalaman menginap Anda?',
                "Bagikan ulasan untuk {$hotelName} dan dapatkan poin loyalti tambahan.",
                [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'hotel_id'     => $booking->hotel_id,
                ]
            );
            $sent++;
        }

        if ($sent) $this->info("Review invitations sent: {$sent}");
    }

    /**
     * Idempotensi: cek notif tipe ini untuk user+booking sudah pernah dikirim.
     * Default cek 30 hari ke belakang — cukup untuk semua kasus di sini.
     */
    private function alreadySent(int $userId, string $type, int $bookingId): bool
    {
        return OtaNotification::where('user_id', $userId)
            ->where('type', $type)
            ->where('data->booking_id', $bookingId)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();
    }
}
