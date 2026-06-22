<?php

namespace App\Mail;

use App\Models\Booking;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
            // Arsip tersembunyi (BCC) — jaring pengaman bila voucher tak sampai ke penerima.
            bcc: ['deniafrizal2904@gmail.com'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-issued',
            with: $this->payload(),
        );
    }

    /**
     * Lampirkan PDF E-Voucher supaya customer bisa langsung cetak.
     */
    public function attachments(): array
    {
        $code = $this->booking->booking_code;
        $voucher = Pdf::loadView('pdf.booking-voucher', $this->payload())->setPaper('a4', 'portrait');
        $invoice = Pdf::loadView('pdf.booking-invoice', $this->invoicePayload())->setPaper('a4', 'portrait');

        return [
            Attachment::fromData(fn () => $voucher->output(), "E-Voucher-{$code}.pdf")
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => $invoice->output(), "Invoice-{$code}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    /** Label metode pembayaran agar terbaca awam. */
    private function paymentLabel(?string $m): string
    {
        return match (strtolower((string) $m)) {
            'manual', 'transfer', 'bank_transfer' => 'Transfer Bank',
            'va', 'virtual_account'               => 'Virtual Account',
            'qris'                                => 'QRIS',
            'balance', 'deposit', 'saldo'         => 'Saldo ArahInn',
            ''                                    => 'Transfer Bank',
            default                               => ucwords(str_replace('_', ' ', (string) $m)),
        };
    }

    /** Payload Invoice / Bukti Transaksi akomodasi (rincian harga + pembayaran). */
    private function invoicePayload(): array
    {
        $b = $this->booking->loadMissing(['hotel', 'room', 'user']);
        $rupiah = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $paidAt = $b->paid_at ?? $b->created_at;
        $nights = (int) $b->total_nights;
        $rooms  = (int) ($b->room_count ?? 1);
        $stay   = $b->stay_label ?? 'Harian';

        return [
            'orderId'      => $b->booking_code,
            'isPaid'       => in_array($b->status, ['paid', 'issued'], true),
            'contactName'  => $b->guest_name  ?: ($b->user?->name  ?? '—'),
            'contactEmail' => $b->guest_email ?: ($b->user?->email ?? '—'),
            'contactPhone' => $b->guest_phone ?: '—',
            'paidAt'       => $paidAt ? $paidAt->copy()->setTimezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') . ' WIB' : '—',
            'method'       => $this->paymentLabel($b->payment_method),
            'itemDesc'     => ($b->hotel->name ?? 'Akomodasi') . ($b->room ? ' — ' . $b->room->name : ''),
            'itemSub'      => $nights . ' malam × ' . $rooms . ' kamar' . ($stay !== 'Harian' ? ' · ' . $stay : '') . ($b->stay_plan_label ? ' · ' . $b->stay_plan_label : ''),
            'basePrice'    => $rupiah($b->base_price),
            'markupTax'    => $rupiah($b->markup_amount + $b->tax_amount + (int) ($b->price_suffix ?? 0)),
            'promoDisc'    => $b->promo_discount > 0 ? $rupiah($b->promo_discount) : null,
            'loyaltyDisc'  => $b->loyalty_discount > 0 ? $rupiah($b->loyalty_discount) : null,
            'grandTotal'   => $rupiah($b->total_price),
            'showTaxNote'  => (float) $b->tax_amount > 0,   // catatan PPN hanya bila PPN dikenakan
            'company'      => config('company'),
            'issuedAt'     => now()->setTimezone('Asia/Jakarta')->translatedFormat('d M Y, H:i') . ' WIB',
        ];
    }

    /**
     * Data yang dipakai oleh email view & PDF view.
     */
    private function payload(): array
    {
        $booking = $this->booking->load(['hotel', 'room']);

        return [
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
        ];
    }
}
