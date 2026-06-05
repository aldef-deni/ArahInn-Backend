<?php

namespace App\Mail;

use App\Models\PpobTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PpobSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public PpobTransaction $trx) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Transaksi Berhasil – {$this->trx->product_name} | ArahInn",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ppob-success', with: self::payload($this->trx));
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('pdf.ppob-receipt', self::payload($this->trx))->setPaper('a4', 'portrait');
        return [
            Attachment::fromData(fn () => $pdf->output(), "E-Struk-{$this->trx->trx_code}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    /** Payload bersama untuk email view + PDF view. */
    public static function payload(PpobTransaction $trx): array
    {
        // template_struk bisa array baris struk dari Rajabiller
        $strukText = null;
        $tpl = $trx->template_struk;
        if (is_array($tpl)) {
            $lines = $tpl['struk'] ?? $tpl['lines'] ?? $tpl;
            if (is_array($lines)) {
                $strukText = collect($lines)->map(fn ($l) => is_array($l) ? implode(' ', $l) : (string) $l)->implode("\n");
            } elseif (is_string($lines)) {
                $strukText = $lines;
            }
        } elseif (is_string($tpl) && $tpl !== '') {
            $strukText = $tpl;
        }

        $statusMap = ['success' => 'Transaksi Berhasil', 'processing' => 'Sedang Diproses'];

        return [
            'trx'         => $trx,
            'statusLabel' => $statusMap[$trx->status] ?? strtoupper($trx->status),
            'totalAmount' => number_format($trx->total_amount, 0, ',', '.'),
            'strukText'   => $strukText,
            'issuedAt'    => ($trx->completed_at ?? $trx->created_at)->translatedFormat('d M Y, H:i'),
            'frontendUrl' => rtrim(config('app.frontend_url') ?: config('app.url'), '/'),
        ];
    }
}
