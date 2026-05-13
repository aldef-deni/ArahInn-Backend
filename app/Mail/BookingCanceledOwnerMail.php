<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingCanceledOwnerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reservasi Dibatalkan – ' . $this->booking->booking_code . ' | Arahinn.com',
        );
    }

    public function content(): Content
    {
        $booking = $this->booking->load(['hotel', 'room']);

        return new Content(
            view: 'emails.booking-canceled-owner',
            with: [
                'booking'     => $booking,
                'hotel'       => $booking->hotel,
                'room'        => $booking->room,
                'checkIn'     => $booking->check_in->translatedFormat('D, d M Y'),
                'checkOut'    => $booking->check_out->translatedFormat('D, d M Y'),
                'nights'      => $booking->total_nights,
                'totalPrice'  => number_format($booking->total_price, 0, ',', '.'),
                'frontendUrl' => rtrim(config('app.frontend_url'), '/'),
            ],
        );
    }
}
