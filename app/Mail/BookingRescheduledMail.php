<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingRescheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string  $oldCheckIn,
        public string  $oldCheckOut,
        public string  $recipient = 'guest', // 'guest' | 'owner'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Jadwal Booking Diubah – ' . $this->booking->booking_code . ' | Arahinn.com',
        );
    }

    public function content(): Content
    {
        $booking = $this->booking->load(['hotel', 'room']);

        return new Content(
            view: 'emails.booking-rescheduled',
            with: [
                'booking'     => $booking,
                'hotel'       => $booking->hotel,
                'room'        => $booking->room,
                'oldCheckIn'  => $this->oldCheckIn,
                'oldCheckOut' => $this->oldCheckOut,
                'newCheckIn'  => $booking->check_in->translatedFormat('D, d M Y'),
                'newCheckOut' => $booking->check_out->translatedFormat('D, d M Y'),
                'nights'      => $booking->total_nights,
                'totalPrice'  => number_format($booking->total_price, 0, ',', '.'),
                'recipient'   => $this->recipient,
                'frontendUrl' => rtrim(config('app.frontend_url'), '/'),
            ],
        );
    }
}
