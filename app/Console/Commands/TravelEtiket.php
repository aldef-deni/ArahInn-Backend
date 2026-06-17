<?php

namespace App\Console\Commands;

use App\Mail\TravelIssuedMail;
use App\Models\TravelBooking;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Cek / render-ulang / kirim-ulang e-tiket sebuah booking travel (by kode).
 * Render dari data tersimpan + template terbaru (nama fix + watermark).
 *
 *   php artisan travel:etiket TRV260614EB6C9                 # cek isi + simpan PDF
 *   php artisan travel:etiket TRV260614EB6C9 --out=etiket.pdf
 *   php artisan travel:etiket TRV260614EB6C9 --email          # kirim ke email penumpang/akun
 *   php artisan travel:etiket TRV260614EB6C9 --email=foo@bar.com
 */
class TravelEtiket extends Command
{
    protected $signature = 'travel:etiket {code : Kode booking ArahInn (mis. TRV260614EB6C9)}
        {--out= : Path simpan PDF (default storage/app/etiket-<code>.pdf)}
        {--email= : Kirim e-tiket ke email ini. Tanpa nilai = pakai email penumpang/akun.}';

    protected $description = 'Cek isi & render/kirim ulang e-tiket travel berdasarkan kode booking.';

    public function handle(): int
    {
        $code = $this->argument('code');
        $b = TravelBooking::with('user:id,name,email')->where('code', $code)->first();
        if (!$b) {
            $this->error("Booking '{$code}' tidak ditemukan.");
            return self::FAILURE;
        }

        $p = TravelIssuedMail::payload($b);

        // ── Ringkasan isi (untuk verifikasi cepat di terminal) ──
        $this->newLine();
        $this->info("E-TIKET {$b->code}  [{$p['modaLabel']}]  status: {$b->status}");
        $this->line("Rute        : {$p['originName']} ({$b->origin})  →  {$p['destinationName']} ({$b->destination})");
        $this->line("Jadwal      : {$p['departDate']}  {$p['departTime']} - {$p['arriveTime']}");
        $this->line("Layanan     : " . ($b->service_name ?: '—') . "  ·  Kelas: " . ($b->class ?: '—'));
        $this->line("Kode vendor : " . ($b->vendor_booking_code ?: '—'));
        $this->line("Total       : Rp {$p['totalPrice']}");
        $this->newLine();

        $pax = $p['pax'] ?? [];
        if (empty($pax)) {
            $this->warn('⚠ Tidak ada data penumpang tersimpan.');
        } else {
            $rows = [];
            $incomplete = 0;
            foreach ($pax as $i => $px) {
                $nameOk = $px['name'] && $px['name'] !== '—';
                $idOk   = !empty($px['id']);
                if (!$nameOk || !$idOk) $incomplete++;
                $rows[] = [$i + 1, $px['name'], $px['type'], $px['id'] ?: '—', $nameOk && $idOk ? 'OK' : 'KURANG'];
            }
            $this->table(['No', 'Nama', 'Tipe', 'Identitas', 'Cek'], $rows);
            $incomplete > 0
                ? $this->warn("⚠ {$incomplete} penumpang datanya belum lengkap (nama/identitas).")
                : $this->info('✓ Semua data penumpang lengkap.');
        }
        $this->newLine();

        // ── Bukti penerbitan dari VENDOR (Rajabiller) — penanda PNR valid/ter-ticketing ──
        $pay = $b->meta['payment'] ?? [];
        $this->info('Bukti penerbitan dari vendor (Rajabiller):');
        $this->line('  PNR/Booking maskapai : ' . ($b->vendor_booking_code ?: '—'));
        $this->line('  Transaction ID vendor: ' . ($b->vendor_transaction_id ?: '—'));
        $this->line('  RC pembayaran vendor : ' . ($pay['rc'] ?? '—') . (isset($pay['rc']) && $pay['rc'] === '00' ? '  (SUKSES)' : ''));
        $this->line('  URL e-tiket resmi    : ' . ($b->url_etiket ?: ($pay['url_etiket'] ?? '— (tidak ada)')));
        $this->line('  URL struk            : ' . ($b->url_struk ?: ($pay['url_struk'] ?? '—')));
        $hasProof = $b->url_etiket || !empty($pay['url_etiket']) || ($pay['rc'] ?? null) === '00';
        $hasProof
            ? $this->info('  ✓ Ada bukti penerbitan resmi vendor → PNR ter-ticketing.')
            : $this->warn('  ⚠ Tidak ada URL/RC sukses vendor tersimpan — verifikasi manual ke Rajabiller sebelum jamin valid.');
        $this->newLine();

        // ── Kirim email bila diminta ──
        // Deteksi --email walau TANPA nilai (flag) lewat hasParameterOption.
        $emailRequested = $this->option('email') !== null || $this->input->hasParameterOption('--email');
        if ($emailRequested) {
            $to = $this->option('email')
                ?: ($b->meta['payment']['contact']['email']
                    ?? $b->meta['book']['contact']['email']
                    ?? $b->user?->email);
            if (!$to) { $this->error('Tidak ada email tujuan. Beri --email=alamat.'); return self::FAILURE; }
            try {
                Mail::to($to)->send(new TravelIssuedMail($b));
                $this->info("✓ E-tiket dikirim ke {$to}");
            } catch (\Throwable $e) {
                $this->error('✗ Gagal kirim: ' . $e->getMessage());
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        // ── Render PDF ke file ──
        $out = $this->option('out') ?: storage_path("app/etiket-{$b->code}.pdf");
        if (!str_starts_with($out, '/') && !preg_match('/^[A-Za-z]:/', $out)) $out = base_path($out);
        $pdf = Pdf::loadView('pdf.travel-ticket', $p)->setPaper('a4', 'portrait');
        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, $pdf->output());
        $this->info("PDF disimpan: {$out} (" . number_format(filesize($out)) . " bytes)");
        $this->line('Tip: tambah --email untuk kirim langsung ke customer.');
        return self::SUCCESS;
    }
}
