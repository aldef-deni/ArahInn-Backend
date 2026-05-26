<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\User;
use App\Mail\BookingIssuedMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Emergency tool untuk re-kirim e-voucher booking ke tamu & owner.
 *
 * Usage:
 *   php artisan voucher:resend ARH123ABCD       (by booking_code)
 *   php artisan voucher:resend 42                (by booking id)
 *   php artisan voucher:resend ARH123ABCD --guest-only
 *   php artisan voucher:resend ARH123ABCD --owner-only
 */
class ResendBookingVoucher extends Command
{
    protected $signature = 'voucher:resend {booking : Booking ID atau booking_code} {--guest-only} {--owner-only}';

    protected $description = 'Resend e-voucher PDF ke tamu dan owner hotel';

    public function handle(): int
    {
        $id = $this->argument('booking');

        // Cari by booking_code dulu, fallback ke id
        $booking = is_numeric($id)
            ? Booking::with(['hotel:id,name,owner_id', 'room'])->find($id)
            : Booking::with(['hotel:id,name,owner_id', 'room'])->where('booking_code', $id)->first();

        if (!$booking) {
            $this->error("Booking '{$id}' tidak ditemukan.");
            return self::FAILURE;
        }

        if (!in_array($booking->status, ['paid', 'issued', 'rescheduled'])) {
            $this->warn("Booking {$booking->booking_code} berstatus '{$booking->status}'. Voucher hanya bisa dikirim untuk status paid/issued/rescheduled.");
            return self::FAILURE;
        }

        $sendGuest = !$this->option('owner-only');
        $sendOwner = !$this->option('guest-only');
        $ok = true;

        if ($sendGuest) {
            if (!$booking->guest_email) {
                $this->warn("→ Email tamu kosong, skip.");
            } else {
                try {
                    Mail::to($booking->guest_email)->send(new BookingIssuedMail($booking));
                    $this->info("✓ Voucher terkirim ke tamu: {$booking->guest_email}");
                } catch (\Throwable $e) {
                    $this->error("✗ Gagal kirim ke tamu: " . $e->getMessage());
                    $ok = false;
                }
            }
        }

        if ($sendOwner) {
            $ownerId    = $booking->hotel?->owner_id;
            $ownerEmail = $ownerId ? User::find($ownerId)?->email : null;
            if (!$ownerEmail) {
                $this->warn("→ Email owner kosong (owner_id={$ownerId}), skip.");
            } else {
                try {
                    Mail::to($ownerEmail)->send(new BookingIssuedMail($booking));
                    $this->info("✓ Voucher terkirim ke owner: {$ownerEmail}");
                } catch (\Throwable $e) {
                    $this->error("✗ Gagal kirim ke owner: " . $e->getMessage());
                    $ok = false;
                }
            }

            // Email tambahan dari Step 3 form daftar hotel
            $extras = is_array($booking->hotel?->voucher_emails) ? $booking->hotel->voucher_emails : [];
            foreach ($extras as $extra) {
                try {
                    Mail::to($extra)->send(new BookingIssuedMail($booking));
                    $this->info("✓ Voucher terkirim ke email tambahan: {$extra}");
                } catch (\Throwable $e) {
                    $this->error("✗ Gagal kirim ke {$extra}: " . $e->getMessage());
                    $ok = false;
                }
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
