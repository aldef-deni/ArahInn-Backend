<?php

namespace App\Mail;

use App\Models\TravelBooking;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TravelIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TravelBooking $booking) {}

    public function envelope(): Envelope
    {
        $label = self::modaConfig($this->booking->moda)['label'];
        return new Envelope(
            subject: "E-Tiket {$label} Terbit – {$this->booking->code} | ArahInn",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.travel-issued', with: self::payload($this->booking));
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('pdf.travel-ticket', self::payload($this->booking))->setPaper('a4', 'portrait');
        return [
            Attachment::fromData(fn () => $pdf->output(), "E-Tiket-{$this->booking->code}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    /** Konfigurasi tampilan per moda. */
    public static function modaConfig(string $moda): array
    {
        return [
            'pesawat' => ['label' => 'Pesawat',    'accent' => '#0284c7', 'soft' => '#e0f2fe', 'service' => 'Maskapai'],
            'kereta'  => ['label' => 'Kereta Api', 'accent' => '#d97706', 'soft' => '#fef3c7', 'service' => 'Kereta'],
            'pelni'   => ['label' => 'Kapal Laut', 'accent' => '#0e7490', 'soft' => '#cffafe', 'service' => 'Kapal'],
        ][$moda] ?? ['label' => 'Travel', 'accent' => '#1d4ed8', 'soft' => '#eff6ff', 'service' => 'Layanan'];
    }

    /** Payload bersama untuk email view + PDF view. */
    public static function payload(TravelBooking $b): array
    {
        $cfg = self::modaConfig($b->moda);
        $fmtTime = fn ($s) => $s && strlen($s) === 4 ? substr($s, 0, 2) . ':' . substr($s, 2, 2) : $s;

        // Flatten penumpang dari berbagai bentuk (adults/children/infants atau list datar)
        $raw  = $b->passengers ?? [];
        $flat = [];
        $push = function ($p, $type) use (&$flat) {
            if (!is_array($p)) return;
            $name = $p['name'] ?? trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? ''));
            $id   = $p['idNumber'] ?? $p['identityNumber'] ?? $p['identity_number'] ?? '';
            $flat[] = ['name' => $name ?: '—', 'type' => $type, 'id' => $id];
        };
        if (isset($raw['adults']) || isset($raw['children']) || isset($raw['infants'])) {
            foreach (['adults' => 'Dewasa', 'children' => 'Anak', 'infants' => 'Bayi'] as $k => $t) {
                foreach ($raw[$k] ?? [] as $p) $push($p, $t);
            }
        } else {
            foreach ($raw as $p) $push($p, 'Penumpang');
        }

        $statusMap = ['issued' => 'E-Tiket Terbit', 'paid' => 'Lunas', 'pending_payment' => 'Menunggu Pembayaran'];

        return [
            'b'               => $b,
            'modaLabel'       => $cfg['label'],
            'serviceLabel'    => $cfg['service'],
            'accent'          => $cfg['accent'],
            'accentSoft'      => $cfg['soft'],
            'statusLabel'     => $statusMap[$b->status] ?? strtoupper($b->status),
            'originName'      => $b->origin_name ?: $b->origin,
            'destinationName' => $b->destination_name ?: $b->destination,
            'departTime'      => $fmtTime($b->depart_time),
            'arriveTime'      => $fmtTime($b->arrive_time),
            'departDate'      => $b->depart_date ? $b->depart_date->translatedFormat('D, d M Y') : '—',
            'totalPrice'      => number_format($b->total_price, 0, ',', '.'),
            'pax'             => $flat,
            'issuedAt'        => ($b->issued_at ?? $b->created_at)->translatedFormat('d M Y, H:i'),
            'frontendUrl'     => rtrim(config('app.frontend_url') ?: config('app.url'), '/'),
        ];
    }
}
