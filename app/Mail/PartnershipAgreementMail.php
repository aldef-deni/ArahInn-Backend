<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnershipAgreementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Hotel $hotel,
        public User  $owner
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Terima Kasih Telah Mendaftarkan Properti Anda di ArahInn.com'
        );
    }

    public function content(): Content
    {
        $extranetUrl = config('app.extranet_url', config('app.frontend_url', 'https://arahinn.com') . '/owner');
        $frontendUrl = config('app.frontend_url', 'https://arahinn.com');
        $hotel       = $this->hotel;
        $owner       = $this->owner;

        return new Content(htmlString: $this->buildEmailHtml($hotel, $owner, $extranetUrl, $frontendUrl));
    }

    public function attachments(): array
    {
        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.partnership_agreement', [
                'hotel' => $this->hotel,
                'owner' => $this->owner,
                'date'  => now()->locale('id')->translatedFormat('d F Y'),
            ]);

            $filename = 'Perjanjian_Kemitraan_Akomodasi_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->hotel->name) . '.pdf';

            return [
                Attachment::fromData(
                    fn () => $pdf->output(),
                    $filename
                )->withMime('application/pdf'),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function buildEmailHtml(Hotel $hotel, User $owner, string $extranetUrl, string $frontendUrl): string
    {
        $hotelName  = e($hotel->name);
        $ownerEmail = e($owner->email);
        $year       = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Terima Kasih Bergabung di ArahInn.com</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 0;">
    <tr><td align="center">
      <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1e40af,#2563eb);padding:32px 40px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-0.3px;">
              Selamat Datang di ArahInn.com!
            </h1>
            <p style="margin:8px 0 0;color:#bfdbfe;font-size:13px;">
              Platform Pemesanan Akomodasi Terpercaya
            </p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px;">
            <p style="margin:0 0 16px;font-size:16px;color:#1e293b;font-weight:600;">
              Hai {$hotelName},
            </p>
            <p style="margin:0 0 16px;font-size:14px;color:#475569;line-height:1.75;">
              Terima kasih sudah memilih <strong>ArahInn.com</strong> sebagai partner pemesanan
              properti Anda via online. Properti Anda sedang dalam proses review oleh tim kami
              dan akan segera aktif setelah diverifikasi.
            </p>
            <p style="margin:0 0 24px;font-size:14px;color:#475569;line-height:1.75;">
              Berikut informasi login ke akun Extranet Anda:
            </p>

            <!-- Login Info Box -->
            <table width="100%" cellpadding="0" cellspacing="0"
              style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:28px;">
              <tr>
                <td style="padding:20px 24px;">
                  <p style="margin:0 0 10px;font-size:13px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                    Info Akun
                  </p>
                  <table cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding:4px 0;font-size:14px;color:#64748b;width:100px;">Masuk ke</td>
                      <td style="padding:4px 0;font-size:14px;color:#1e293b;font-weight:600;">
                        : <a href="{$extranetUrl}" style="color:#2563eb;text-decoration:none;">{$extranetUrl}</a>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:4px 0;font-size:14px;color:#64748b;">Username</td>
                      <td style="padding:4px 0;font-size:14px;color:#1e293b;font-weight:600;">
                        : {$ownerEmail}
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 28px;font-size:14px;color:#475569;line-height:1.75;">
              Terlampir <strong>Perjanjian Kemitraan Akomodasi</strong> yang sudah Anda setujui
              pada saat pendaftaran. Harap simpan dokumen ini sebagai arsip Anda.
            </p>

            <!-- CTA Button -->
            <div style="text-align:center;">
              <a href="{$extranetUrl}"
                style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:14px;font-weight:700;letter-spacing:0.5px;">
                LENGKAPI DETAIL PROPERTI
              </a>
            </div>
          </td>
        </tr>

        <!-- Divider -->
        <tr>
          <td style="padding:0 40px;">
            <hr style="border:none;border-top:1px solid #e2e8f0;margin:0;">
          </td>
        </tr>

        <!-- Tips -->
        <tr>
          <td style="padding:24px 40px;">
            <p style="margin:0 0 12px;font-size:13px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
              Langkah Selanjutnya
            </p>
            <table cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td style="padding:6px 0;">
                  <span style="display:inline-block;width:22px;height:22px;background:#dbeafe;color:#1d4ed8;border-radius:50%;text-align:center;line-height:22px;font-size:12px;font-weight:700;margin-right:10px;vertical-align:middle;">1</span>
                  <span style="font-size:13px;color:#475569;vertical-align:middle;">Login ke Extranet menggunakan email terdaftar</span>
                </td>
              </tr>
              <tr>
                <td style="padding:6px 0;">
                  <span style="display:inline-block;width:22px;height:22px;background:#dbeafe;color:#1d4ed8;border-radius:50%;text-align:center;line-height:22px;font-size:12px;font-weight:700;margin-right:10px;vertical-align:middle;">2</span>
                  <span style="font-size:13px;color:#475569;vertical-align:middle;">Lengkapi detail kamar, harga, dan foto properti</span>
                </td>
              </tr>
              <tr>
                <td style="padding:6px 0;">
                  <span style="display:inline-block;width:22px;height:22px;background:#dbeafe;color:#1d4ed8;border-radius:50%;text-align:center;line-height:22px;font-size:12px;font-weight:700;margin-right:10px;vertical-align:middle;">3</span>
                  <span style="font-size:13px;color:#475569;vertical-align:middle;">Tunggu verifikasi dari tim ArahInn (1–3 hari kerja)</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;border-radius:0 0 16px 16px;">
            <p style="margin:0 0 6px;font-size:12px;color:#94a3b8;">
              Jika Anda memiliki pertanyaan, hubungi kami di
              <a href="mailto:support@arahinn.com" style="color:#2563eb;text-decoration:none;">support@arahinn.com</a>
            </p>
            <p style="margin:0;font-size:12px;color:#94a3b8;">
              &copy; {$year} ArahInn.com &mdash; Platform Akomodasi Terlengkap Indonesia
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
