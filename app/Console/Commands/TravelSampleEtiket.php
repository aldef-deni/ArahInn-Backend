<?php

namespace App\Console\Commands;

use App\Mail\TravelIssuedMail;
use App\Models\TravelBooking;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Render CONTOH e-tiket pesawat (data dummy) ke PDF — untuk preview tampilan.
 * Tidak menyentuh database / vendor. Murni rendering template pdf.travel-ticket.
 *
 *   php artisan travel:sample-etiket
 *   php artisan travel:sample-etiket --out=storage/app/contoh-etiket.pdf
 */
class TravelSampleEtiket extends Command
{
    protected $signature = 'travel:sample-etiket
        {--out= : Path output PDF (default: storage/app/contoh-etiket-pesawat.pdf)}
        {--email= : Kirim contoh e-tiket (TravelIssuedMail) ke alamat ini sebagai tes.}';
    protected $description = 'Render contoh e-tiket pesawat (dummy) ke PDF / kirim email tes.';

    public function handle(): int
    {
        // Booking dummy — tidak disimpan ke DB.
        $b = new TravelBooking();
        $b->forceFill([
            'moda'                  => 'pesawat',
            'code'                  => 'TRV260614X7K2P',
            'vendor_booking_code'   => 'GIA-QH8F2L',
            'vendor_transaction_id' => 'TRX-998877',
            'airline'               => 'TPGA',
            'origin'                => 'CGK',
            'destination'           => 'DPS',
            'origin_name'           => 'Jakarta (CGK) — Soekarno-Hatta',
            'destination_name'      => 'Denpasar (DPS) — Ngurah Rai',
            'depart_date'           => now()->addDays(7)->startOfDay(),
            'depart_time'           => '0915',
            'arrive_time'           => '1210',
            'service_name'          => 'Garuda Indonesia · GA-406',
            'class'                 => 'Ekonomi (Y)',
            'pax'                   => 2,
            'vendor_price'          => 1840000,
            'markup'                => 60000,
            'total_price'           => 1960000,
            'status'                => 'issued',
            'issued_at'             => now(),
            'passengers'            => [
                'adults' => [
                    ['name' => 'Bapak Deni Afrizal', 'birthdate' => '1982-04-29', 'idNumber' => '3201xxxxxxxx0001'],
                    ['name' => 'Ibu Siti Rahma',     'birthdate' => '1988-11-12', 'idNumber' => '3201xxxxxxxx0002'],
                ],
                'children' => [],
                'infants'  => [],
            ],
        ]);
        // created_at fallback untuk payload (kalau issued_at null)
        $b->setRawAttributes(array_merge($b->getAttributes(), ['created_at' => now()]), true);

        // Kirim email tes bila --email diberikan.
        if ($email = $this->option('email')) {
            $this->line("Mengirim contoh e-tiket ke {$email} ...");
            try {
                Mail::to($email)->send(new TravelIssuedMail($b));
                $this->info("✓ Email terkirim ke {$email} (cek inbox / folder spam).");
            } catch (\Throwable $e) {
                $this->error('✗ Gagal kirim email: ' . $e->getMessage());
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        $out = $this->option('out') ?: storage_path('app/contoh-etiket-pesawat.pdf');
        if (!str_starts_with($out, '/') && !preg_match('/^[A-Za-z]:/', $out)) {
            $out = base_path($out);
        }

        $pdf = Pdf::loadView('pdf.travel-ticket', TravelIssuedMail::payload($b))->setPaper('a4', 'portrait');
        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, $pdf->output());

        $this->info('Contoh e-tiket pesawat dibuat:');
        $this->line('  ' . $out);
        $this->line('  Ukuran: ' . number_format(filesize($out)) . ' bytes');
        return self::SUCCESS;
    }
}
