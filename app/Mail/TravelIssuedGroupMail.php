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
        return $this->bookings->map(function (TravelBooking $b) {
            $p = TravelIssuedMail::payload($b);
            $p['legName'] = $b->leg === 'return' ? 'Penerbangan Pulang' : 'Penerbangan Pergi';
            return $p;
        })->values()->all();
    }

    public function envelope(): Envelope
    {
        $code = $this->bookings->first()?->group_code ?? $this->bookings->first()?->code;
        return new Envelope(subject: "E-Tiket Pesawat Pulang-Pergi Terbit – {$code} | ArahInn");
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
        return [
            Attachment::fromData(fn () => $pdf->output(), "E-Tiket-PP-{$code}.pdf")->withMime('application/pdf'),
        ];
    }
}
