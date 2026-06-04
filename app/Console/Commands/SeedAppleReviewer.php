<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Seed dedicated reviewer account for Apple App Store / Google Play submission.
 *
 * Idempotent — safe to run multiple times. Cleans up old reviewer bookings,
 * recreates 2 fresh ones (1 pending, 1 issued) using earliest available
 * hotel + room.
 *
 * Usage:
 *   php artisan reviewer:seed
 *   php artisan reviewer:seed --email=custom@arahinn.com --password=Custom123!
 */
class SeedAppleReviewer extends Command
{
    protected $signature = 'reviewer:seed
        {--email=appstorereview@arahinn.com}
        {--password=ArahInnReview2026!}
        {--name=Apple Reviewer}';

    protected $description = 'Seed/refresh a demo reviewer account with dummy bookings for App Store / Play Store submission.';

    public function handle(): int
    {
        $email    = $this->option('email');
        $password = $this->option('password');
        $name     = $this->option('name');

        $this->info("┌─────────────────────────────────────────────┐");
        $this->info("│ Seeding reviewer account                    │");
        $this->info("└─────────────────────────────────────────────┘");

        // 1. Create/update user — match common backend fields. Adjust if your
        //    schema differs (some installs use `password_hash` instead).
        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'name'              => $name,
            'phone'             => '+6285100000000',
            'password'          => Hash::make($password),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'role'              => 'user',
        ])->save();

        // Spatie role — assign 'user' if package available
        if (class_exists(Role::class)) {
            try {
                $user->syncRoles(['user']);
            } catch (\Throwable $e) {
                $this->warn("  ⚠ Could not assign Spatie role: " . $e->getMessage());
            }
        }

        $this->info("  ✓ User upserted: id={$user->id} email={$email}");

        // 2. Pick a hotel + room to use for dummy bookings. Prefer first hotel
        //    that has at least one room. Fallback: skip booking seed.
        $hotel = Hotel::whereHas('rooms')->orderBy('id')->first();

        if (!$hotel) {
            $this->error("  ✗ No hotel with rooms found — skipping booking seed.");
            $this->printCredentials($email, $password);
            return self::SUCCESS;
        }

        $room = $hotel->rooms()->orderBy('id')->first();
        $this->info("  ✓ Using hotel: {$hotel->name} (id={$hotel->id})");
        $this->info("  ✓ Using room: {$room->name} (id={$room->id})");

        // 3. Clean up old reviewer bookings so we always start fresh
        $deleted = Booking::where('user_id', $user->id)->delete();
        if ($deleted) {
            $this->info("  ✓ Cleaned up {$deleted} old booking(s)");
        }

        // 4. Calculate baseline pricing from room base price
        $basePerNight = (float) ($room->base_price ?? $room->basePrice ?? 500_000);

        // 5. Create ISSUED booking (1 month ago — completed stay)
        $checkInIssued  = now()->subDays(30)->toDateString();
        $checkOutIssued = now()->subDays(28)->toDateString();
        $nightsIssued   = 2;

        $issuedBooking = Booking::create([
            'booking_code'     => Booking::generateCode(),
            'user_id'          => $user->id,
            'hotel_id'         => $hotel->id,
            'room_id'          => $room->id,
            'check_in'         => $checkInIssued,
            'check_out'        => $checkOutIssued,
            'total_nights'     => $nightsIssued,
            'guests'           => 2,
            'room_count'       => 1,
            'base_price'       => $basePerNight * $nightsIssued,
            'markup_amount'    => 0,
            'promo_discount'   => 0,
            'loyalty_discount' => 0,
            'tax_amount'       => 0,
            'total_price'      => $basePerNight * $nightsIssued,
            'price_suffix'     => 0,
            'status'           => 'issued',
            'guest_name'       => $name,
            'guest_email'      => $email,
            'guest_phone'      => '+6285100000000',
            'notes'            => 'Apple App Store reviewer demo — completed booking.',
            'issued_at'        => now()->subDays(31),
        ]);
        $this->info("  ✓ Issued booking created: {$issuedBooking->booking_code}");

        // 6. Create PENDING booking (next week — visible "Bayar Sekarang" flow)
        $checkInPending  = now()->addDays(7)->toDateString();
        $checkOutPending = now()->addDays(9)->toDateString();
        $nightsPending   = 2;

        $pendingBooking = Booking::create([
            'booking_code'     => Booking::generateCode(),
            'user_id'          => $user->id,
            'hotel_id'         => $hotel->id,
            'room_id'          => $room->id,
            'check_in'         => $checkInPending,
            'check_out'        => $checkOutPending,
            'total_nights'     => $nightsPending,
            'guests'           => 2,
            'room_count'       => 1,
            'base_price'       => $basePerNight * $nightsPending,
            'markup_amount'    => 0,
            'promo_discount'   => 0,
            'loyalty_discount' => 0,
            'tax_amount'       => 0,
            'total_price'      => $basePerNight * $nightsPending,
            'price_suffix'     => 0,
            'status'           => 'pending',
            'guest_name'       => $name,
            'guest_email'      => $email,
            'guest_phone'      => '+6285100000000',
            'notes'            => 'Apple App Store reviewer demo — pending payment.',
            'expires_at'       => now()->addDays(1),
        ]);
        $this->info("  ✓ Pending booking created: {$pendingBooking->booking_code}");

        $this->printCredentials($email, $password);

        return self::SUCCESS;
    }

    private function printCredentials(string $email, string $password): void
    {
        $this->newLine();
        $this->info("┌─────────────────────────────────────────────┐");
        $this->info("│ DEMO CREDENTIALS — kasih ini ke Apple       │");
        $this->info("├─────────────────────────────────────────────┤");
        $this->info("│ Email    : {$email}" . str_repeat(' ', max(0, 33 - strlen($email))) . "│");
        $this->info("│ Password : {$password}" . str_repeat(' ', max(0, 33 - strlen($password))) . "│");
        $this->info("└─────────────────────────────────────────────┘");
        $this->newLine();
        $this->comment("⚠  JANGAN ubah/hapus akun ini selama proses App Review berlangsung.");
        $this->comment("   Apple bisa retry login berkali-kali. Kalau gagal → app di-reject.");
    }
}
