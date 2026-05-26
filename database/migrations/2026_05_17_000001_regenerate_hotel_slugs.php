<?php

use App\Models\Hotel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Regenerate slug untuk semua hotel — strip timestamp suffix, append -2 -3 dst kalau bentrok
        // Pakai pendekatan manual (bukan Hotel::generateUniqueSlug) supaya bisa proses 1 per 1
        // dan verify uniqueness antar-hotel pada migration ini sendiri.

        $used = []; // slug => true
        Hotel::orderBy('id')->chunkById(200, function ($hotels) use (&$used) {
            foreach ($hotels as $hotel) {
                $base = Str::slug($hotel->name) ?: 'hotel';
                $slug = $base;
                $i    = 2;
                while (isset($used[$slug])) {
                    $slug = $base . '-' . $i++;
                }
                $used[$slug] = true;

                if ($hotel->slug !== $slug) {
                    $hotel->slug = $slug;
                    $hotel->saveQuietly();
                }
            }
        });
    }

    public function down(): void
    {
        // Tidak ada rollback — slug timestamp lama tidak bisa di-restore secara akurat
    }
};
