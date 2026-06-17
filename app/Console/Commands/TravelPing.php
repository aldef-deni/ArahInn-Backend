<?php

namespace App\Console\Commands;

use App\Services\TravelService;
use Illuminate\Console\Command;

/**
 * Diagnostik koneksi Rajabiller Travel TANPA membeli tiket.
 *
 * Melakukan sign-in ke channel production (pesawat/pelni) dan/atau devel (kereta),
 * lalu menampilkan: config efektif (URL/outlet/PIN tersamar), RC + pesan, dan
 * saldo deposit. Opsional cek tarik daftar bandara untuk memastikan endpoint hidup.
 *
 * Gunakan ini untuk memastikan credential + IP whitelist (103.76.121.180) sudah benar.
 *
 * Usage:
 *   php artisan travel:ping                 # cek channel pesawat/pelni (production)
 *   php artisan travel:ping --channel=kai   # cek channel kereta (devel)
 *   php artisan travel:ping --all           # cek kedua channel
 *   php artisan travel:ping --airports      # + uji tarik daftar bandara (production)
 */
class TravelPing extends Command
{
    protected $signature = 'travel:ping
        {--channel=prod : Channel yang dicek: prod (pesawat/pelni) atau kai (kereta).}
        {--all : Cek kedua channel (prod + kai).}
        {--airports : Setelah sign-in, uji tarik daftar bandara (hanya channel prod).}';

    protected $description = 'Diagnostik koneksi Rajabiller Travel (sign-in + saldo) tanpa beli tiket.';

    public function handle(TravelService $travel): int
    {
        $channels = $this->option('all')
            ? [TravelService::CH_PROD, TravelService::CH_KAI]
            : [$this->option('channel') === 'kai' ? TravelService::CH_KAI : TravelService::CH_PROD];

        $cfg = config('services.raja_travel');
        $hasError = false;

        foreach ($channels as $channel) {
            $label = $channel === TravelService::CH_KAI
                ? 'KERETA (devel)'
                : 'PESAWAT/PELNI (production)';

            $url    = $channel === TravelService::CH_KAI ? ($cfg['kai_url'] ?? '')       : ($cfg['prod_url'] ?? '');
            $outlet = $channel === TravelService::CH_KAI ? ($cfg['kai_outlet_id'] ?? '') : ($cfg['prod_outlet_id'] ?? '');
            $pin    = $channel === TravelService::CH_KAI ? ($cfg['kai_pin'] ?? '')       : ($cfg['prod_pin'] ?? '');

            $this->newLine();
            $this->line("══════════════════════════════════════════════════");
            $this->info("  Channel: {$label}  [{$channel}]");
            $this->line("══════════════════════════════════════════════════");
            $this->line("  URL      : " . ($url ?: '(KOSONG!)'));
            $this->line("  Outlet ID: " . ($outlet ?: '(KOSONG!)'));
            $this->line("  PIN      : " . $this->maskPin($pin));

            if (empty($url) || empty($outlet) || empty($pin)) {
                $this->error("  ✗ Config belum lengkap — isi RAJA_TRAVEL_* di .env.");
                $hasError = true;
                continue;
            }

            $this->line("  → Sign-in...");
            $start = microtime(true);
            $res   = $travel->signIn($channel);
            $ms    = (int) round((microtime(true) - $start) * 1000);

            $rc      = $res['rc'] ?? null;
            $rd      = $res['rd'] ?? null;
            $http    = $res['_http_status'] ?? 0;
            $balance = $res['balance'] ?? null;
            $ok      = TravelService::isSuccess($rc) && !empty($res['token']);

            $this->line("  HTTP     : {$http}   ({$ms} ms)");
            $this->line("  RC       : " . ($rc ?? 'null') . "  — " . ($rd ?: TravelService::userMessage($rc)));

            if ($ok) {
                $this->info("  ✓ SIGN-IN BERHASIL — token diterima.");
                $this->info("  Saldo deposit: " . $this->rupiah($balance));
            } else {
                $hasError = true;
                $this->error("  ✗ SIGN-IN GAGAL.");
                $this->diagnose($rc, $http);
            }

            // Opsional: uji endpoint bandara (hanya prod)
            if ($this->option('airports') && $channel === TravelService::CH_PROD && $ok) {
                $this->line("  → Tarik daftar bandara...");
                $air  = $travel->airports();
                $arc  = $air['rc'] ?? null;
                $list = $air['data'] ?? [];
                if (TravelService::isSuccess($arc) && is_array($list)) {
                    $this->info("  ✓ Bandara OK — " . count($list) . " entri diterima.");
                } else {
                    $this->error("  ✗ Tarik bandara gagal — RC " . ($arc ?? 'null') . " " . ($air['rd'] ?? ''));
                    $hasError = true;
                }
            }
        }

        $this->newLine();
        if ($hasError) {
            $this->error('Selesai dengan ERROR. Periksa credential / IP whitelist / saldo.');
            return self::FAILURE;
        }
        $this->info('Selesai — semua channel sehat. ✓');
        return self::SUCCESS;
    }

    /** Saran penyebab berdasar RC / HTTP. */
    private function diagnose(?string $rc, int $http): void
    {
        if ($http === 0) {
            $this->warn("    → Tidak ada respons (timeout/koneksi). Cek IP server di-whitelist (103.76.121.180) & firewall.");
            return;
        }
        if (in_array($http, [401, 403], true)) {
            $this->warn("    → HTTP {$http}: kemungkinan IP belum di-whitelist atau akses ditolak vendor.");
        }
        match ($rc) {
            '01'    => $this->warn("    → RC 01: outlet/PIN salah untuk channel ini. Konfirmasi PIN travel-production ke Rajabiller."),
            '06'    => $this->warn("    → RC 06: saldo deposit tidak cukup. Top-up deposit Rajabiller."),
            'CONFIG'=> $this->warn("    → Credential channel kosong di config/.env."),
            'ERR'   => $this->warn("    → Koneksi ke vendor gagal (network/timeout)."),
            default => null,
        };
    }

    private function maskPin(string $pin): string
    {
        if ($pin === '') return '(KOSONG!)';
        $len = strlen($pin);
        return $len <= 2 ? str_repeat('*', $len) : substr($pin, 0, 1) . str_repeat('*', $len - 2) . substr($pin, -1);
    }

    private function rupiah($v): string
    {
        if ($v === null || !is_numeric($v)) return (string) ($v ?? '-');
        return 'Rp ' . number_format((float) $v, 0, ',', '.');
    }
}
