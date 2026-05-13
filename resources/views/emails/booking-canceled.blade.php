<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Dibatalkan – {{ $booking->booking_code }}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f0f4f8; font-family: 'Inter', Arial, sans-serif; color: #1a202c; -webkit-font-smoothing: antialiased; }
  a { color: inherit; text-decoration: none; }
</style>
</head>
<body style="background:#f0f4f8; margin:0; padding:0;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;">
  <tr>
    <td align="center" style="padding:32px 16px 48px;">
      <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%;">

        {{-- ── HEADER ── --}}
        <tr>
          <td style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%); border-radius:16px 16px 0 0; padding:36px 40px 32px; text-align:center;">
            <img src="{{ $frontendUrl }}/logo-arahin.png" alt="Arahinn.com" style="height:48px; width:auto; display:block; margin:0 auto;">
            <p style="font-size:11px; color:rgba(255,255,255,0.65); margin-top:8px; letter-spacing:2px; text-transform:uppercase; font-family:Arial,sans-serif;">
              Accommodation · Transportation · Activities
            </p>
            <div style="margin-top:20px;">
              <span style="display:inline-block; background:#ef4444; color:#fff; border-radius:99px; padding:7px 20px; font-size:13px; font-weight:700; letter-spacing:0.3px;">
                ✕ &nbsp; Booking Dibatalkan
              </span>
            </div>
          </td>
        </tr>

        {{-- ── CARD ── --}}
        <tr>
          <td style="background:#fff; border-radius:0 0 16px 16px; box-shadow:0 4px 24px rgba(0,0,0,0.08); overflow:hidden;">

            {{-- Alert Banner --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fef2f2; border-bottom:1px solid #fecaca; padding:20px 40px;">
                  <table cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="vertical-align:top; padding-right:14px; font-size:22px; line-height:1;">⚠️</td>
                      <td>
                        <p style="font-size:14px; font-weight:700; color:#7f1d1d; margin-bottom:5px; font-family:Arial,sans-serif;">Booking Anda telah dibatalkan</p>
                        <p style="font-size:13px; color:#991b1b; line-height:1.6; font-family:Arial,sans-serif;">
                          Jika Anda tidak melakukan pembatalan ini atau memiliki pertanyaan, segera hubungi tim kami di
                          <a href="mailto:support@arahinn.com" style="color:#991b1b; text-decoration:underline; font-weight:600;">support@arahinn.com</a>
                        </p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            {{-- Booking Code --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:28px 40px 8px;">
                  <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2; border:1.5px dashed #ef4444; border-radius:12px; overflow:hidden;">
                    <tr>
                      <td style="padding:16px 22px;">
                        <p style="font-size:11px; color:#94a3b8; font-family:Arial,sans-serif; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">Kode Booking</p>
                        <p style="font-size:22px; font-weight:700; color:#ef4444; letter-spacing:3px; font-family:'Courier New',monospace;">{{ $booking->booking_code }}</p>
                      </td>
                      <td style="padding:16px 22px; text-align:right; vertical-align:top;">
                        <p style="font-size:11px; color:#94a3b8; font-family:Arial,sans-serif; margin-bottom:4px;">Dibatalkan pada</p>
                        <p style="font-size:12px; font-weight:700; color:#64748b; font-family:Arial,sans-serif;">
                          {{ $booking->canceled_at?->setTimezone('Asia/Jakarta')->format('d M Y, H:i') ?? now()->setTimezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                        </p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            {{-- Detail Pesanan --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:20px 40px 0;">
                  <p style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:14px; font-family:Arial,sans-serif;">Detail Pesanan</p>
                </td>
              </tr>
              <tr>
                <td style="padding:0 40px 28px;">
                  <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #f1f5f9; border-radius:12px; overflow:hidden;">

                    {{-- Row: Properti --}}
                    <tr style="border-bottom:1px solid #f8fafc;">
                      <td width="45%" style="padding:12px 18px; font-size:13px; color:#64748b; font-family:Arial,sans-serif; background:#fafbfc;">Properti</td>
                      <td width="55%" style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; font-family:Arial,sans-serif; text-align:right;">{{ $hotel->name ?? '-' }}</td>
                    </tr>

                    {{-- Row: Tipe Kamar --}}
                    <tr style="border-bottom:1px solid #f8fafc;">
                      <td style="padding:12px 18px; font-size:13px; color:#64748b; font-family:Arial,sans-serif; background:#fafbfc;">Tipe Kamar</td>
                      <td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; font-family:Arial,sans-serif; text-align:right;">{{ $room->name ?? '-' }}</td>
                    </tr>

                    {{-- Row: Check-in --}}
                    <tr style="border-bottom:1px solid #f8fafc;">
                      <td style="padding:12px 18px; font-size:13px; color:#64748b; font-family:Arial,sans-serif; background:#fafbfc;">Check-in</td>
                      <td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; font-family:Arial,sans-serif; text-align:right;">{{ $checkIn }}</td>
                    </tr>

                    {{-- Row: Check-out --}}
                    <tr style="border-bottom:1px solid #f8fafc;">
                      <td style="padding:12px 18px; font-size:13px; color:#64748b; font-family:Arial,sans-serif; background:#fafbfc;">Check-out</td>
                      <td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; font-family:Arial,sans-serif; text-align:right;">{{ $checkOut }}</td>
                    </tr>

                    {{-- Row: Durasi --}}
                    <tr style="border-bottom:1px solid #f8fafc;">
                      <td style="padding:12px 18px; font-size:13px; color:#64748b; font-family:Arial,sans-serif; background:#fafbfc;">Durasi</td>
                      <td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; font-family:Arial,sans-serif; text-align:right;">{{ $nights }} malam</td>
                    </tr>

                    {{-- Row: Total --}}
                    <tr>
                      <td style="padding:14px 18px; font-size:13px; font-weight:700; color:#1a202c; font-family:Arial,sans-serif; background:#fafbfc; border-top:2px solid #f1f5f9;">Total yang Dibayar</td>
                      <td style="padding:14px 18px; font-size:15px; font-weight:700; color:#ef4444; font-family:Arial,sans-serif; text-align:right; border-top:2px solid #f1f5f9;">Rp {{ $totalPrice }}</td>
                    </tr>

                  </table>
                </td>
              </tr>
            </table>

            {{-- Data Tamu --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:0 40px 28px; border-top:1px solid #f1f5f9;">
                  <p style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin:24px 0 14px; font-family:Arial,sans-serif;">Data Tamu</p>
                  <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #f1f5f9; border-radius:12px; overflow:hidden;">
                    <tr style="border-bottom:1px solid #f8fafc;">
                      <td width="45%" style="padding:12px 18px; font-size:13px; color:#64748b; font-family:Arial,sans-serif; background:#fafbfc;">Nama</td>
                      <td width="55%" style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; font-family:Arial,sans-serif; text-align:right;">{{ $booking->guest_name }}</td>
                    </tr>
                    <tr>
                      <td style="padding:12px 18px; font-size:13px; color:#64748b; font-family:Arial,sans-serif; background:#fafbfc;">Email</td>
                      <td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; font-family:Arial,sans-serif; text-align:right;">{{ $booking->guest_email }}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            {{-- CTA --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="padding:8px 40px 36px;">
                  <a href="{{ $frontendUrl }}/orders"
                    style="display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; font-size:14px; font-weight:700; padding:14px 40px; border-radius:10px; font-family:Arial,sans-serif; letter-spacing:0.2px;">
                    Lihat Riwayat Pesanan
                  </a>
                </td>
              </tr>
            </table>

          </td>
        </tr>

        {{-- ── FOOTER ── --}}
        <tr>
          <td align="center" style="padding:28px 16px 0;">
            <p style="font-size:11px; color:#94a3b8; line-height:1.8; font-family:Arial,sans-serif; text-align:center;">
              Email ini dikirim otomatis oleh <strong style="color:#64748b;">Arahinn.com</strong><br>
              Jika ada pertanyaan, hubungi kami di
              <a href="mailto:support@arahinn.com" style="color:#2563eb; text-decoration:underline;">support@arahinn.com</a><br><br>
              &copy; {{ date('Y') }} Arahinn.com &nbsp;·&nbsp; Semua hak dilindungi.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
