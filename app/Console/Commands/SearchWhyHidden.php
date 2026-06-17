<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomPrice;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Diagnostik: kenapa sebuah akomodasi muncul / tidak muncul di Search Hotel
 * untuk tanggal tertentu. Menyelaraskan dengan HotelController::unavailableRoomIds()
 * dan BookingService::assertAllotment().
 *
 * Contoh:
 *   php artisan search:why-hidden "2308" 2026-06-19
 *   php artisan search:why-hidden "2308" 2026-06-19 2026-06-20
 */
class SearchWhyHidden extends Command
{
    protected $signature = 'search:why-hidden {keyword : Nama/sebagian nama hotel} {checkin : YYYY-MM-DD} {checkout? : YYYY-MM-DD (default checkin+1)}';
    protected $description = 'Cek alasan akomodasi muncul/tidak muncul di Search Hotel untuk rentang tanggal';

    public function handle(): int
    {
        $keyword  = $this->argument('keyword');
        $checkIn  = $this->argument('checkin');
        $checkOut = $this->argument('checkout') ?: Carbon::parse($checkIn)->addDay()->format('Y-m-d');

        $nights = [];
        for ($c = Carbon::parse($checkIn)->startOfDay(), $end = Carbon::parse($checkOut)->startOfDay();
             $c->lt($end); $c->addDay()) {
            $nights[] = $c->format('Y-m-d');
        }
        if (empty($nights)) {
            $nights = [Carbon::parse($checkIn)->format('Y-m-d')];
        }

        $this->info("Rentang malam: " . implode(', ', $nights));

        $hotels = Hotel::with(['rooms'])->where('name', 'like', "%{$keyword}%")->get();
        if ($hotels->isEmpty()) {
            $this->error("Tidak ada hotel cocok dengan '{$keyword}'.");
            return self::FAILURE;
        }

        foreach ($hotels as $hotel) {
            $this->line('');
            $this->info("HOTEL #{$hotel->id} — {$hotel->name}  [status: {$hotel->status}]");
            if ($hotel->status !== 'approved') {
                $this->warn('  ⚠ status BUKAN approved → tidak akan tampil di search apa pun.');
            }

            $rooms = $hotel->rooms;
            if ($rooms->isEmpty()) {
                $this->warn('  ⚠ tidak punya kamar.');
                continue;
            }

            $hotelHasAvailableRoom = false;

            foreach ($rooms as $room) {
                $reasons = [];
                foreach ($nights as $d) {
                    $price = RoomPrice::where('room_id', $room->id)->whereDate('date', $d)->first();

                    $closed    = $price && $price->is_available === false;
                    $allotment = ($price && $price->available_units !== null)
                        ? (int) $price->available_units
                        : (int) $room->total_units;

                    $booked = Booking::where('room_id', $room->id)
                        ->where(function ($q) {
                            $q->whereIn('status', ['paid', 'issued'])
                              ->orWhere(function ($qq) {
                                  $qq->where('status', 'pending')
                                     ->where(function ($qqq) {
                                         $qqq->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                     });
                              });
                        })
                        ->where('check_in', '<=', $d)
                        ->where('check_out', '>', $d)
                        ->sum('room_count');

                    $sisa = $allotment - (int) $booked;

                    if ($closed)      $reasons[] = "{$d}: CLOSED-OUT (is_available=false)";
                    elseif ($sisa <= 0) $reasons[] = "{$d}: PENUH (allotment={$allotment}, booked={$booked}, sisa={$sisa})";
                }

                $ok = empty($reasons) && $room->is_active;
                if ($ok) $hotelHasAvailableRoom = true;

                $flag = $room->is_active ? '' : ' [NONAKTIF]';
                $verdict = $ok ? '✅ AVAILABLE' : '❌ BLOCKED';
                $this->line("  Room #{$room->id} {$room->name}{$flag} (total_units={$room->total_units}) → {$verdict}");
                foreach ($reasons as $r) $this->line("       - {$r}");
                if (!$room->is_active) $this->line("       - kamar nonaktif (is_active=false)");
            }

            $this->line($hotelHasAvailableRoom
                ? "  ➜ HASIL: TAMPIL di search ✅"
                : "  ➜ HASIL: TIDAK tampil di search ❌");
        }

        return self::SUCCESS;
    }
}
