<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Selamat Datang di Arahinn.com!');
    }

    public function content(): Content
    {
        $name     = $this->user->name;
        $frontendUrl = config('app.frontend_url', 'https://arahinn.com');

        return new Content(htmlString: "
<!DOCTYPE html>
<html lang='id'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f7fb;padding:40px 0;'>
    <tr><td align='center'>
      <table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);'>
        <tr>
          <td style='background:linear-gradient(135deg,#3b5bdb,#1971c2);padding:36px 40px;text-align:center;'>
            <h1 style='margin:0;color:#fff;font-size:26px;font-weight:700;letter-spacing:-0.5px;'>Selamat Datang di Arahinn.com!</h1>
          </td>
        </tr>
        <tr>
          <td style='padding:36px 40px;'>
            <p style='margin:0 0 16px;font-size:16px;color:#1e293b;'>Halo, <strong>{$name}</strong>!</p>
            <p style='margin:0 0 16px;font-size:14px;color:#475569;line-height:1.7;'>
              Akun Anda telah berhasil dibuat. Kini Anda dapat memesan hotel, resort, dan villa terbaik di seluruh Indonesia dengan harga terjangkau.
            </p>
            <p style='margin:0 0 24px;font-size:14px;color:#475569;line-height:1.7;'>
              Mulai jelajahi ribuan pilihan akomodasi dan temukan penginapan impian Anda.
            </p>
            <div style='text-align:center;'>
              <a href='{$frontendUrl}' style='display:inline-block;background:#3b5bdb;color:#fff;text-decoration:none;padding:14px 32px;border-radius:10px;font-size:15px;font-weight:600;'>
                Mulai Pesan Sekarang
              </a>
            </div>
          </td>
        </tr>
        <tr>
          <td style='background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;'>
            <p style='margin:0;font-size:12px;color:#94a3b8;'>
              &copy; " . date('Y') . " Arahinn.com &mdash; Aplikasi Akomodasi Terlengkap
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
        ");
    }
}
