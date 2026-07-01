<?php

namespace App\Mail;

use App\Models\{User, Booking};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// ─── Helpers ─────────────────────────────────────────
function formatRp(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function baseHtml(string $title, string $body, string $brand = 'Arahinn'): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width">
<title>{$title}</title>
<style>
  body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}
  .wrap{max-width:600px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)}
  .hdr{background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;padding:28px 32px;text-align:center}
  .hdr h1{margin:0;font-size:22px}
  .hdr p{margin:6px 0 0;opacity:.8;font-size:13px}
  .body{padding:28px 32px;color:#333}
  .info-box{background:#f8f9fa;border-left:4px solid #2563eb;border-radius:4px;padding:14px 18px;margin:16px 0}
  .row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #eee;font-size:14px}
  .row:last-child{border:none}
  .label{color:#666}
  .val{font-weight:600}
  .total-val{color:#1d4ed8;font-size:18px}
  .btn{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;margin:16px 0}
  .footer{text-align:center;padding:18px;background:#f8f9fa;color:#999;font-size:12px}
  .badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700}
  .badge-success{background:#e6f9f0;color:#00875a}
  .badge-warning{background:#fff8e1;color:#f57f17}
  .badge-danger{background:#ffebee;color:#c62828}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr"><h1>🏨 {$brand}</h1><p>Pesan Hotel Terbaik, Harga Terbaik</p></div>
  <div class="body">{$body}</div>
  <div class="footer">© {$year} {$brand} · Jika ada pertanyaan, email cs@arahinn.com</div>
</div>
</body>
</html>
HTML;
}

// =====================================================
// WELCOME MAIL
// =====================================================
class WelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public function __construct(public User $user, public bool $isOwner = false) {}

    public function envelope(): Envelope {
        $brand = $this->isOwner ? 'My ArahInn' : 'Arahinn';
        return new Envelope(subject: "Selamat Datang di {$brand}! 🎉");
    }

    public function content(): Content {
        $brand = $this->isOwner ? 'My ArahInn' : 'Arahinn';
        $cta   = $this->isOwner
            ? "<a class='btn' href='" . config('app.frontend_url') . "/owner'>Masuk ke Dashboard Mitra</a>"
            : "<a class='btn' href='" . config('app.frontend_url') . "'>Cari Hotel Sekarang</a>";
        $intro = $this->isOwner
            ? "<p>Mulai kelola properti & reservasi Anda di dashboard mitra.</p>"
            : "<p>Mulai temukan dan pesan hotel terbaik di seluruh Indonesia.</p>";
        $body = "<h2>Selamat datang, {$this->user->name}! 👋</h2>
<p>Terima kasih telah mendaftar di {$brand}. Akun Anda sudah aktif!</p>
{$intro}
{$cta}";
        return new Content(htmlString: baseHtml('Selamat Datang', $body, $brand));
    }
}

// =====================================================
// BOOKING CONFIRMATION
// =====================================================
class BookingConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope {
        return new Envelope(subject: "Konfirmasi Booking {$this->booking->booking_code} — Selesaikan Pembayaran");
    }

    public function content(): Content {
        $b = $this->booking;
        $body = "<h2>Booking Berhasil Dibuat! ✅</h2>
<p>Halo <strong>{$b->guest_name}</strong>, booking Anda berhasil. Segera selesaikan pembayaran.</p>
<div class='info-box'>
  <div class='row'><span class='label'>Kode Booking</span><span class='val'>{$b->booking_code}</span></div>
  <div class='row'><span class='label'>Hotel</span><span class='val'>{$b->hotel?->name}</span></div>
  <div class='row'><span class='label'>Kamar</span><span class='val'>{$b->room?->name} ({$b->room?->type})</span></div>
  <div class='row'><span class='label'>Check-in</span><span class='val'>{$b->check_in?->format('d M Y')}</span></div>
  <div class='row'><span class='label'>Check-out</span><span class='val'>{$b->check_out?->format('d M Y')}</span></div>
  <div class='row'><span class='label'>Tamu</span><span class='val'>{$b->guests} orang · {$b->total_nights} malam</span></div>
  <div class='row'><span class='label'>Total Pembayaran</span><span class='val total-val'>" . formatRp($b->total_price) . "</span></div>
</div>
<p>⚠️ <strong>Bayar dalam 30 menit</strong> agar booking tidak otomatis dibatalkan.</p>
<a class='btn' href='" . config('app.frontend_url') . "/payment/{$b->id}'>Bayar Sekarang</a>";
        return new Content(htmlString: baseHtml('Konfirmasi Booking', $body));
    }
}

// BookingIssuedMail  → app/Mail/BookingIssuedMail.php  (Blade template)
// BookingCanceledMail → app/Mail/BookingCanceledMail.php (Blade template)

// =====================================================
// NEW RESERVATION (dikirim ke Owner Hotel)
// =====================================================
class NewReservationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public function __construct(public Booking $booking) {}

    public function envelope(): Envelope {
        return new Envelope(subject: "Reservasi Baru — Itinerary ID {$this->booking->booking_code}");
    }

    /**
     * Hitung rincian pendapatan mitra + data untuk PDF E-Voucher owner.
     * Basis pendapatan owner = base_price − diskon yang ditanggung ArahInn.
     * Komisi nominal = basis − owner_payout (sesuai skema beban diskon).
     */
    private function voucherData(): array {
        $b = $this->booking->loadMissing(['hotel', 'room']);

        // Formula resmi: owner = (harga setelah dipotong campaign) × (1 − [komisi% + 2% PPh]).
        //   • basis komisi = base_price + discount_owner  (= harga − diskon campaign/ArahInn).
        //   • rate = komisi sesuai jenis menginap + 2% PPh:
        //       harian  → commission_percent (fallback 12% bila NULL)
        //       mingguan→ commission_percent_weekly  (NULL → 0, tanpa komisi)
        //       bulanan → commission_percent_monthly (NULL → 0, tanpa komisi)
        $PPH        = 2.0;
        $isLongStay = ($b->stay_type ?? 'daily') !== 'daily';
        $commBase   = max(0, (float) $b->base_price + (float) $b->discount_owner);
        $fmtPct     = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

        if ($isLongStay) {
            $col     = ($b->stay_type === 'monthly') ? 'commission_percent_monthly' : 'commission_percent_weekly';
            $stayPct = $b->hotel?->{$col};
            if ($stayPct === null) {
                $effRate = 0.0;
                $pctText = '';
            } else {
                $effRate = ((float) $stayPct + $PPH) / 100;
                $pctText = ' (' . $fmtPct($stayPct) . '% + PPh ' . $fmtPct($PPH) . '%)';
            }
        } else {
            $commPct = $b->hotel?->commission_percent;
            if ($commPct === null) {
                $effRate = (float) config('ota.markup_percent', 12) / 100;   // 12% (sudah termasuk PPh)
                $pctText = ' (' . $fmtPct(config('ota.markup_percent', 12)) . '%)';
            } else {
                $effRate = ((float) $commPct + $PPH) / 100;
                $pctText = ' (' . $fmtPct($commPct) . '% + PPh ' . $fmtPct($PPH) . '%)';
            }
        }

        $commNom = round($commBase * $effRate, 2);
        $payout  = round($commBase - $commNom, 2);

        // Label "Harga Kamar" dinamis sesuai diskon yang dipakai (promo kode / campaign / keduanya).
        //   campaign_discount & code_discount disimpan sejak migrasi diskon-breakdown;
        //   booking lama (kolom null) → fallback: promo_id menandai pemakaian kode promo.
        $campDisc = $b->campaign_discount;
        $codeDisc = $b->code_discount;
        if ($campDisc !== null || $codeDisc !== null) {
            $hasCampaign = (float) $campDisc > 0;
            $hasPromo    = (float) $codeDisc > 0;
        } else {
            $hasPromo    = $b->promo_id !== null;
            $hasCampaign = !$hasPromo && (float) $b->promo_discount > 0;
        }
        $suffix = '';
        if ($hasPromo && $hasCampaign)  $suffix = ' (setelah diskon promo & campaign)';
        elseif ($hasPromo)              $suffix = ' (setelah diskon promo)';
        elseif ($hasCampaign)           $suffix = ' (setelah diskon campaign)';

        return [
            'booking'          => $b,
            'hotel'            => $b->hotel,
            'room'             => $b->room,
            'checkIn'          => $b->check_in?->translatedFormat('D, d M Y') ?? '-',
            'checkOut'         => $b->check_out?->translatedFormat('D, d M Y') ?? '-',
            'nights'           => $b->total_nights,
            'priceBase'        => $commBase,   // harga kamar basis komisi
            'priceLabel'       => 'Harga Kamar' . $suffix,
            'priceSuffix'      => $suffix,
            'ownerPayout'      => $payout,
            'commissionNominal'=> $effRate > 0 ? $commNom : null,
            'commissionPctText'=> $pctText,
        ];
    }

    public function content(): Content {
        $vd = $this->voucherData();

        return new Content(
            view: 'emails.new-reservation',
            with: [
                'booking'           => $vd['booking'],
                'hotel'             => $vd['hotel'],
                'room'              => $vd['room'],
                'checkIn'           => $vd['checkIn'],
                'checkOut'          => $vd['checkOut'],
                'nights'            => $vd['nights'],
                'frontendUrl'       => rtrim(config('app.frontend_url', 'https://my.arahinn.com'), '/'),
                'priceLabel'        => $vd['priceLabel'],
                'priceSuffix'       => $vd['priceSuffix'],
                'priceBaseRp'       => formatRp($vd['priceBase']),
                'ownerPayoutRp'     => formatRp($vd['ownerPayout']),
                'commissionRp'      => $vd['commissionNominal'] !== null ? formatRp($vd['commissionNominal']) : null,
                'commissionPctText' => $vd['commissionPctText'],
                'totalPriceRp'      => formatRp((float) $vd['booking']->total_price),
            ],
        );
    }

    /** Lampirkan PDF E-Voucher khusus mitra (gaya tiket.com) ke email owner. */
    public function attachments(): array {
        $code    = $this->booking->booking_code;
        $voucher = Pdf::loadView('pdf.owner-voucher', $this->voucherData())->setPaper('a4', 'portrait');

        return [
            Attachment::fromData(fn () => $voucher->output(), "E-Voucher-Mitra-{$code}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}

// =====================================================
// PASSWORD RESET
// =====================================================
class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public function __construct(public User $user, public string $resetUrl) {}

    public function envelope(): Envelope {
        return new Envelope(subject: 'Reset Password Arahinn');
    }

    public function content(): Content {
        $body = "<h2>Reset Password</h2>
<p>Halo <strong>{$this->user->name}</strong>,</p>
<p>Klik tombol di bawah untuk mereset password Anda:</p>
<a class='btn' href='{$this->resetUrl}'>Reset Password</a>
<p style='color:#888;font-size:12px'>Link berlaku selama <strong>1 jam</strong>. Jika tidak meminta reset, abaikan email ini.</p>";
        return new Content(htmlString: baseHtml('Reset Password', $body));
    }
}
