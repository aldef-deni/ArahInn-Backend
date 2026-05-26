<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class DokuMerchantPicker
{
    private const COUNTER_KEY = 'doku_merchant_rr_counter';

    /**
     * Pilih merchant berikutnya secara round-robin dari pool yang aktif.
     * Counter disimpan di cache supaya tetap fair walau load balancer.
     */
    public function pick(): string
    {
        $keys = DokuService::availableMerchantKeys();

        if (empty($keys)) {
            return 'default';
        }

        if (count($keys) === 1) {
            return $keys[0];
        }

        $counter = (int) Cache::get(self::COUNTER_KEY, 0);
        Cache::put(self::COUNTER_KEY, $counter + 1, now()->addDays(30));

        return $keys[$counter % count($keys)];
    }

    /**
     * Versi deterministik: merchant dipilih berdasar booking ID.
     * Berguna agar booking yang sama selalu landed di merchant yang sama
     * (mis. kalau user retry pembayaran).
     */
    public function pickFor(int $bookingId): string
    {
        $keys = DokuService::availableMerchantKeys();

        if (empty($keys)) {
            return 'default';
        }

        return $keys[$bookingId % count($keys)];
    }
}
