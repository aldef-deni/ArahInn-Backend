<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Dikonfirmasi – ' . $this->booking->booking_code . ' | Arahinn.com',
        );
    }

    public function content(): Content
    {
        $booking = $this->booking->load(['hotel', 'room']);

        return new Content(
            view: 'emails.booking-issued',
            with: [
                'booking'     => $booking,
                'hotel'       => $booking->hotel,
                'room'        => $booking->room,
                'checkIn'     => $booking->check_in->translatedFormat('D, d M Y'),
                'checkOut'    => $booking->check_out->translatedFormat('D, d M Y'),
                'nights'      => $booking->total_nights,
                'totalPrice'  => number_format($booking->total_price, 0, ',', '.'),
                'basePrice'   => number_format($booking->base_price, 0, ',', '.'),
                'markupAmt'   => number_format($booking->markup_amount, 0, ',', '.'),
                'taxAmt'      => number_format($booking->tax_amount, 0, ',', '.'),
                'promoDisc'   => number_format($booking->promo_discount ?? 0, 0, ',', '.'),
                'loyaltyDisc' => number_format($booking->loyalty_discount ?? 0, 0, ',', '.'),
                'priceSuffix' => (int) ($booking->price_suffix ?? 0),
                'appUrl'      => rtrim(config('app.url'), '/'),
                'frontendUrl' => rtrim(config('app.frontend_url'), '/'),
            ],
        );
    }
}
