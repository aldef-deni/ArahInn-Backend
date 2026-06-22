<?php

namespace App\Support;

use App\Services\TravelService;

/**
 * Catatan penting e-tiket pesawat per maskapai (ketentuan bagasi + syarat pengangkutan),
 * tersedia dalam Bahasa Indonesia & English (bilingual voucher).
 * Kode maskapai mengikuti format vendor Rajabiller (TPxx) — lihat TravelService::AIRLINE_NAMES.
 */
class AirlineNotes
{
    /** Ketentuan bagasi per kode maskapai — Bahasa Indonesia. */
    private const BAGGAGE_ID = [
        'TPGA' => 'bagasi kabin 7KG dan bagasi tercatat 20KG',     // Garuda Indonesia
        'TPQG' => 'bagasi kabin 7KG dan bagasi tercatat 15KG',     // Citilink
        'TPJT' => 'bagasi tercatat 15KG',                          // Lion Air
        'TPIW' => 'bagasi tercatat 10KG',                          // Wings Air
        'TPID' => 'bagasi kabin 7KG dan bagasi tercatat 20KG',     // Batik Air
        'TPSJ' => 'bagasi kabin 7KG dan bagasi tercatat 20KG',     // Sriwijaya Air
        'TPIN' => 'bagasi kabin 7KG dan bagasi tercatat 20KG',     // NAM Air
        'TPQZ' => 'bagasi kabin 7KG (bagasi tercatat berbayar)',   // Indonesia AirAsia
    ];

    /** Ketentuan bagasi per kode maskapai — English. */
    private const BAGGAGE_EN = [
        'TPGA' => 'cabin baggage 7KG and checked baggage 20KG',
        'TPQG' => 'cabin baggage 7KG and checked baggage 15KG',
        'TPJT' => 'checked baggage 15KG',
        'TPIW' => 'checked baggage 10KG',
        'TPID' => 'cabin baggage 7KG and checked baggage 20KG',
        'TPSJ' => 'cabin baggage 7KG and checked baggage 20KG',
        'TPIN' => 'cabin baggage 7KG and checked baggage 20KG',
        'TPQZ' => 'cabin baggage 7KG (checked baggage chargeable)',
    ];

    /**
     * Daftar catatan e-tiket pesawat.
     * @param  string  $lang  'id' (default) atau 'en'
     * @return string[]
     */
    public static function for(?string $code, string $lang = 'id'): array
    {
        return $lang === 'en' ? self::en($code) : self::id($code);
    }

    /** Teks bagasi singkat (untuk baris "Ketentuan Tiket"). */
    public static function baggage(?string $code, string $lang = 'id'): string
    {
        $code = strtoupper(trim((string) $code));
        $map = $lang === 'en' ? self::BAGGAGE_EN : self::BAGGAGE_ID;
        $default = $lang === 'en' ? 'cabin baggage 7KG and checked baggage 20KG' : 'bagasi kabin 7KG dan bagasi tercatat 20KG';
        return ucfirst($map[$code] ?? $default);
    }

    /** Catatan Bahasa Indonesia. */
    private static function id(?string $code): array
    {
        $code = strtoupper(trim((string) $code));
        $name = TravelService::AIRLINE_NAMES[$code] ?? null;
        $baggage = self::BAGGAGE_ID[$code] ?? 'bagasi kabin 7KG dan bagasi tercatat 20KG';

        $notes = [
            'E-tiket Anda tersimpan elektronik di sistem maskapai dan tunduk pada syarat & ketentuan pengangkutan.',
            'Bawa e-tiket ini beserta kartu identitas asli saat bepergian (diperlukan di konter check-in/bandara).',
            'Tiba di bandara 2 jam sebelum keberangkatan (penerbangan domestik) atau 3 jam (penerbangan internasional).',
            'Check-in ditutup 45 menit sebelum waktu keberangkatan.',
            'Berada di gerbang (gate) 30 menit sebelum keberangkatan agar tidak tertinggal pesawat.',
            'Ketentuan bagasi: ' . $baggage . ($name ? ' untuk ' . $name : '') . '.',
        ];
        if ($code === 'TPQZ') {
            $notes[] = 'Untuk penumpang AirAsia, disarankan melakukan Web Check-in melalui situs resmi AirAsia maksimal 4 jam sebelum keberangkatan; bila tidak, dapat dikenakan biaya tambahan check-in di bandara.';
        }
        $notes[] = 'Penumpang menyetujui Syarat & Ketentuan pengangkutan yang ditetapkan oleh ' . ($name ?: 'maskapai terkait') . '.';

        return $notes;
    }

    /** Catatan English. */
    private static function en(?string $code): array
    {
        $code = strtoupper(trim((string) $code));
        $name = TravelService::AIRLINE_NAMES[$code] ?? null;
        $baggage = self::BAGGAGE_EN[$code] ?? 'cabin baggage 7KG and checked baggage 20KG';

        $notes = [
            'Your airline e-ticket is electronically stored in the airline system and is subject to the conditions of contract.',
            'Please bring this electronic ticket receipt and your identity card when travelling, in case required at the airport/check-in counter.',
            'Please arrive at the airport 2 hours before departure for domestic flights, or 3 hours for international flights.',
            'Check-in closes 45 minutes before departure time.',
            'Please be at the gate 30 minutes before departure time to avoid unnecessary delays.',
            'Baggage allowance: ' . $baggage . ($name ? ' for ' . $name : '') . '.',
        ];
        if ($code === 'TPQZ') {
            $notes[] = 'For AirAsia passengers, Web Check-in via the official AirAsia website is recommended up to 4 hours before departure; otherwise an additional airport check-in fee may apply.';
        }
        $notes[] = 'Passengers agree with the Terms and Conditions of carriage outlined by ' . ($name ?: 'the airline') . '.';

        return $notes;
    }
}
