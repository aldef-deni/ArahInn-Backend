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
use Illuminate\Support\Collection;

/**
 * E-tiket PULANG-PERGI digabung jadi 1 email + 1 PDF (2 leg / 2 halaman).
 * Logo ArahInn & watermark mengikuti standar e-tiket tunggal.
 */
class TravelIssuedGroupMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param Collection<int,TravelBooking> $bookings urut: depart → return */
    public function __construct(public Collection $bookings) {}

    /** Payload tiap leg + label Pergi/Pulang. */
    private function legs(): array
    {
        // PP selalu urut: leg ke-0 = pergi, ke-1 = pulang. Pakai kolom `leg` ATAU posisi
        // (andal walau kolom leg kosong di sebagian data).
        return $this->bookings->values()->map(function (TravelBooking $b, $i) {
            $p = TravelIssuedMail::payload($b);
            $isReturn = $b->leg === 'return' || $i > 0;
            $p['legName'] = $isReturn ? 'Penerbangan Pulang' : 'Penerbangan Pergi';
            return $p;
        })->all();
    }

    public function envelope(): Envelope
    {
        $code = $this->bookings->first()?->group_code ?? $this->bookings->first()?->code;
        return new Envelope(
            subject: "E-Tiket Pesawat Pulang-Pergi Terbit – {$code} | ArahInn",
            // Arsip tersembunyi (BCC) — jaring pengaman bila e-tiket tak sampai ke penerima.
            bcc: ['deniafrizal2904@gmail.com'],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.travel-issued-group', with: [
            'legs'        => $this->legs(),
            'frontendUrl' => rtrim(config('app.frontend_url') ?: config('app.url'), '/'),
            'accent'      => TravelIssuedMail::modaConfig('pesawat')['accent'],
            'groupTotal'  => number_format($this->bookings->sum('total_price'), 0, ',', '.'),
        ]);
    }

    public function attachments(): array
    {
        $code = $this->bookings->first()?->group_code ?? 'PP';
        $pdf  = Pdf::loadView('pdf.travel-ticket-group', ['legs' => $this->legs()])->setPaper('a4', 'portrait');
        $inv  = Pdf::loadView('pdf.travel-invoice', TravelIssuedMail::invoicePayload($this->bookings))->setPaper('a4', 'portrait');
        return [
            Attachment::fromData(fn () => $pdf->output(), "E-Tiket-PP-{$code}.pdf")->withMime('application/pdf'),
            Attachment::fromData(fn () => $inv->output(), "Invoice-PP-{$code}.pdf")->withMime('application/pdf'),
        ];
    }
}
