<?php

namespace App\Mail;

use App\Models\{User, Booking};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// ─── Helpers ─────────────────────────────────────────
function formatRp(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function baseHtml(string $title, string $body): string {
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
  <div class="hdr"><h1>🏨 OTA Arahinn</h1><p>Pesan Hotel Terbaik, Harga Terbaik</p></div>
  <div class="body">{$body}</div>
  <div class="footer">© 2025 OTA Arahinn · Jika ada pertanyaan, email cs@arahinn.com</div>
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
    public function __construct(public User $user) {}

    public function envelope(): Envelope {
        return new Envelope(subject: 'Selamat Datang di OTA Arahinn! 🎉');
    }

    public function content(): Content {
        $body = "<h2>Selamat datang, {$this->user->name}! 👋</h2>
<p>Terima kasih telah mendaftar di OTA Arahinn. Akun Anda sudah aktif!</p>
<p>Mulai temukan dan pesan hotel terbaik di seluruh Indonesia.</p>
<a class='btn' href='" . config('app.frontend_url') . "'>Cari Hotel Sekarang</a>";
        return new Content(htmlString: baseHtml('Selamat Datang', $body));
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

    public function content(): Content {
        $b      = $this->booking;
        $ci     = $b->check_in?->translatedFormat('D, d M Y') ?? $b->check_in?->format('D, d M Y');
        $co     = $b->check_out?->translatedFormat('D, d M Y') ?? $b->check_out?->format('D, d M Y');
        $nights = $b->total_nights;
        $bookedAt = $b->created_at?->format('d M Y') . ' · ' . $b->created_at?->format('H:i');

        $notesRow = $b->notes
            ? "<div class='row'><span class='label'>Catatan Tamu</span><span class='val'>" . htmlspecialchars($b->notes) . "</span></div>"
            : '';
        $phoneRow = $b->guest_phone
            ? "<div class='row'><span class='label'>Telepon Tamu</span><span class='val'>" . htmlspecialchars($b->guest_phone) . "</span></div>"
            : '';

        $body = "
<h2 style='color:#1d4ed8;font-size:20px;margin:0 0 6px'>Hi " . htmlspecialchars($b->hotel?->name ?? 'Mitra Properti') . ",</h2>
<p style='margin:0 0 8px;color:#475569'>Terima kasih telah menjadi mitra properti <strong>Arahinn.com</strong>.</p>
<p style='margin:0 0 20px;color:#334155'>Anda mendapatkan <strong>reservasi baru</strong>. E-voucher sudah dikirimkan ke tamu.</p>

<table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:20px'>
  <tr>
    <td style='background:#f8faff;padding:14px 20px;border-bottom:1px solid #e2e8f0'>
      <table width='100%' cellpadding='0' cellspacing='0'>
        <tr>
          <td><span style='font-weight:700;font-size:15px;color:#1e293b'>Reservation Details</span></td>
          <td align='right'><span style='color:#94a3b8;font-size:12px'>Booked on {$bookedAt}</span></td>
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style='padding:20px'>

      <!-- Check-in / Nights / Check-out -->
      <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:18px'>
        <tr>
          <td width='38%' valign='top'>
            <div style='font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em'>Check-in</div>
            <div style='font-weight:700;font-size:15px;color:#1e293b;margin-top:5px'>{$ci}</div>
          </td>
          <td width='24%' align='center' valign='middle'>
            <div style='font-size:12px;color:#64748b;text-align:center;padding-top:14px'>
              <span style='display:block;width:100%;border-top:1px dashed #cbd5e1;margin-bottom:6px'></span>
              {$nights} Malam
            </div>
          </td>
          <td width='38%' align='right' valign='top'>
            <div style='font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;text-align:right'>Check-out</div>
            <div style='font-weight:700;font-size:15px;color:#1e293b;margin-top:5px;text-align:right'>{$co}</div>
          </td>
        </tr>
      </table>

      <!-- Room detail -->
      <table width='100%' cellpadding='0' cellspacing='0' style='border-top:1px solid #f1f5f9;padding-top:16px'>
        <tr>
          <td valign='top'>
            <div style='font-weight:700;color:#1e293b;font-size:14px'>" . htmlspecialchars($b->room?->name ?? '-') . "</div>
            <div style='color:#94a3b8;font-size:11px;margin-top:3px;text-transform:uppercase;letter-spacing:.05em'>Room and Rate Plan</div>
            <div style='color:#475569;font-size:13px;margin-top:4px'>" . htmlspecialchars(ucfirst($b->room?->type ?? '')) . " · Room only</div>
          </td>
          <td align='right' valign='top'>
            <span style='color:#2563eb;font-size:12px'>Itinerary ID: {$b->booking_code}</span>
          </td>
        </tr>
      </table>

      <!-- Info rows -->
      <div style='margin-top:16px'>
        <div class='row'><span class='label'>Jumlah Kamar</span><span class='val'>1 kamar</span></div>
        <div class='row'><span class='label'>Jumlah Tamu</span><span class='val'>{$b->guests} orang</span></div>
        <div class='row'><span class='label'>Nama Tamu</span><span class='val'>" . htmlspecialchars($b->guest_name) . "</span></div>
        <div class='row'><span class='label'>Email Tamu</span><span class='val'>" . htmlspecialchars($b->guest_email) . "</span></div>
        {$phoneRow}
        {$notesRow}
        <div class='row'><span class='label'>Total Pembayaran</span><span class='val total-val'>" . formatRp((float)$b->total_price) . "</span></div>
      </div>

    </td>
  </tr>
</table>

<p style='color:#64748b;font-size:13px'>Pastikan kamar sudah siap sebelum tanggal check-in. Jika ada pertanyaan, hubungi <a href='mailto:cs@arahinn.com' style='color:#2563eb;text-decoration:none'>cs@arahinn.com</a>.</p>
<a class='btn' href='" . config('app.frontend_url', 'https://extranet.arahinn.com') . "/owner/pesanan'>Lihat Semua Reservasi</a>";

        return new Content(htmlString: baseHtml('Reservasi Baru', $body));
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
        return new Envelope(subject: 'Reset Password OTA Arahinn');
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
