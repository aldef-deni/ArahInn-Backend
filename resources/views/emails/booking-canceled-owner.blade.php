<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservasi Dibatalkan – {{ $booking->booking_code }}</title>
</head>
<body style="background:#f0f4f8; margin:0; padding:0; font-family:Arial,sans-serif; -webkit-font-smoothing:antialiased;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;">
  <tr>
    <td align="center" style="padding:32px 16px 48px;">
      <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px; width:100%;">

        {{-- HEADER --}}
        <tr>
          <td style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%); border-radius:16px 16px 0 0; padding:36px 40px 32px; text-align:center;">
            <img src="{{ $frontendUrl }}/logo-arahin.png" alt="Arahinn.com" style="height:48px; width:auto; display:block; margin:0 auto;">
            <p style="font-size:11px; color:rgba(255,255,255,0.65); margin-top:8px; letter-spacing:2px; text-transform:uppercase;">Accommodation · Transportation · Activities</p>
            <div style="margin-top:20px;">
              <span style="display:inline-block; background:#ef4444; color:#fff; border-radius:99px; padding:7px 20px; font-size:13px; font-weight:700;">
                ✕ &nbsp; Reservasi Dibatalkan
              </span>
            </div>
          </td>
        </tr>

        {{-- CARD --}}
        <tr>
          <td style="background:#fff; border-radius:0 0 16px 16px; box-shadow:0 4px 24px rgba(0,0,0,0.08);">

            {{-- Alert --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fef2f2; border-bottom:1px solid #fecaca; padding:20px 40px;">
                  <p style="font-size:14px; font-weight:700; color:#7f1d1d; margin-bottom:5px;">Informasi Pembatalan Reservasi</p>
                  <p style="font-size:13px; color:#991b1b; line-height:1.6;">
                    Tamu <strong>{{ $booking->guest_name }}</strong> telah membatalkan reservasi di properti Anda.
                    Kamar dapat kembali tersedia untuk tamu lain.
                  </p>
                </td>
              </tr>
            </table>

            {{-- Booking Code --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:28px 40px 8px;">
                  <table width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2; border:1.5px dashed #ef4444; border-radius:12px;">
                    <tr>
                      <td style="padding:16px 22px;">
                        <p style="font-size:11px; color:#94a3b8; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">Kode Booking</p>
                        <p style="font-size:22px; font-weight:700; color:#ef4444; letter-spacing:3px; font-family:'Courier New',monospace;">{{ $booking->booking_code }}</p>
                      </td>
                      <td style="padding:16px 22px; text-align:right; vertical-align:top;">
                        <p style="font-size:11px; color:#94a3b8; margin-bottom:4px;">Dibatalkan pada</p>
                        <p style="font-size:12px; font-weight:700; color:#64748b;">
                          {{ ($booking->canceled_at ?? now())->setTimezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                        </p>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            {{-- Detail Reservasi --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:20px 40px 0;">
                  <p style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:14px;">Detail Reservasi</p>
                </td>
              </tr>
              <tr>
                <td style="padding:0 40px 36px;">
                  <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #f1f5f9; border-radius:12px; overflow:hidden;">
                    <tr><td width="45%" style="padding:12px 18px; font-size:13px; color:#64748b; background:#fafbfc; border-bottom:1px solid #f8fafc;">Properti</td><td width="55%" style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; text-align:right; border-bottom:1px solid #f8fafc;">{{ $hotel->name ?? '-' }}</td></tr>
                    <tr><td style="padding:12px 18px; font-size:13px; color:#64748b; background:#fafbfc; border-bottom:1px solid #f8fafc;">Tipe Kamar</td><td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; text-align:right; border-bottom:1px solid #f8fafc;">{{ $room->name ?? '-' }}</td></tr>
                    <tr><td style="padding:12px 18px; font-size:13px; color:#64748b; background:#fafbfc; border-bottom:1px solid #f8fafc;">Check-in</td><td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; text-align:right; border-bottom:1px solid #f8fafc;">{{ $checkIn }}</td></tr>
                    <tr><td style="padding:12px 18px; font-size:13px; color:#64748b; background:#fafbfc; border-bottom:1px solid #f8fafc;">Check-out</td><td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; text-align:right; border-bottom:1px solid #f8fafc;">{{ $checkOut }}</td></tr>
                    <tr><td style="padding:12px 18px; font-size:13px; color:#64748b; background:#fafbfc; border-bottom:1px solid #f8fafc;">Nama Tamu</td><td style="padding:12px 18px; font-size:13px; font-weight:600; color:#1a202c; text-align:right; border-bottom:1px solid #f8fafc;">{{ $booking->guest_name }}</td></tr>
                    <tr><td style="padding:14px 18px; font-size:13px; font-weight:700; color:#1a202c; background:#fafbfc; border-top:2px solid #f1f5f9;">Nilai Reservasi</td><td style="padding:14px 18px; font-size:15px; font-weight:700; color:#ef4444; text-align:right; border-top:2px solid #f1f5f9;">Rp {{ $totalPrice }}</td></tr>
                  </table>
                </td>
              </tr>
            </table>

            {{-- CTA --}}
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="padding:0 40px 36px;">
                  <a href="{{ $frontendUrl }}/owner/pesanan"
                    style="display:inline-block; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; font-size:14px; font-weight:700; padding:14px 40px; border-radius:10px; letter-spacing:0.2px;">
                    Lihat Semua Reservasi
                  </a>
                </td>
              </tr>
            </table>

          </td>
        </tr>

        {{-- FOOTER --}}
        <tr>
          <td align="center" style="padding:28px 16px 0;">
            <p style="font-size:11px; color:#94a3b8; line-height:1.8; text-align:center;">
              Email ini dikirim otomatis oleh <strong style="color:#64748b;">Arahinn.com</strong><br>
              Jika ada pertanyaan, hubungi kami di <a href="mailto:support@arahinn.com" style="color:#2563eb; text-decoration:underline;">support@arahinn.com</a><br><br>
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
