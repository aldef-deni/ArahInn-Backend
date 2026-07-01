<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="x-apple-disable-message-reformatting">
<title>Reservasi Baru – {{ $booking->booking_code }}</title>
<style>
  body, table, td, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
  img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
  body { margin: 0; padding: 0; width: 100% !important; background: #eef2f7; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1e293b; }
  a { color: #2563eb; text-decoration: none; }
</style>
</head>
<body style="margin:0; padding:0; background:#eef2f7;">

<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#eef2f7; padding:32px 12px;">
  <tr>
    <td align="center">

      <!-- Container -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px; width:100%; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 4px 32px rgba(15,23,42,0.08);">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#0f1e3d 0%,#1d4ed8 100%); padding:36px 32px 28px; text-align:center;">
            <img src="{{ $frontendUrl }}/logo-arahin.png" alt="Arahinn" width="120" style="height:auto; display:block; margin:0 auto 12px;">
            <div style="font-size:10px; color:rgba(255,255,255,0.75); letter-spacing:2.5px; text-transform:uppercase;">Accommodation · Transportation · Activities</div>
            <div style="margin-top:22px;">
              <span style="display:inline-block; background:#22c55e; color:#fff; padding:7px 18px; border-radius:99px; font-size:13px; font-weight:600;">
                ✓&nbsp; Reservasi Baru Diterima
              </span>
            </div>
          </td>
        </tr>

        <!-- Greeting -->
        <tr>
          <td style="padding:28px 36px 0;">
            <h1 style="margin:0 0 6px; font-size:20px; font-weight:700; color:#0f1e3d; letter-spacing:-0.3px;">
              Halo, {{ $hotel->name ?? 'Mitra ArahInn' }} 👋
            </h1>
            <p style="margin:0; font-size:14px; line-height:1.7; color:#475569;">
              Anda menerima <strong style="color:#1d4ed8;">reservasi baru</strong> melalui Arahinn.com. E-voucher telah dikirimkan ke tamu, dan
              <strong>E-Voucher Mitra</strong> (PDF) sudah dilampirkan dalam email ini.
            </p>
          </td>
        </tr>

        <!-- Booking Code Card -->
        <tr>
          <td style="padding:24px 36px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%); border:1.5px dashed #2563eb; border-radius:12px;">
              <tr>
                <td style="padding:18px 22px;">
                  <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px;">Itinerary ID</div>
                  <div style="font-family:'Courier New',monospace; font-size:24px; font-weight:700; color:#1d4ed8; letter-spacing:3px;">{{ $booking->booking_code }}</div>
                </td>
                <td style="padding:18px 22px; text-align:right; font-size:11px; color:#64748b; line-height:1.5;">
                  Dipesan<br>
                  <strong style="color:#1e293b;">{{ $booking->created_at->setTimezone('Asia/Jakarta')->format('d M Y · H:i') }} WIB</strong>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Dates Row -->
        <tr>
          <td style="padding:22px 36px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
              <tr>
                <td width="40%" style="padding:18px 18px; text-align:center; vertical-align:top;">
                  <div style="font-size:10px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:1px;">Check-in</div>
                  <div style="font-size:15px; font-weight:700; color:#0f1e3d; margin-top:6px;">{{ $checkIn }}</div>
                  <div style="font-size:10px; color:#94a3b8; margin-top:3px;">mulai 14:00 WIB</div>
                </td>
                <td width="20%" align="center" style="vertical-align:middle;">
                  <span style="display:inline-block; background:#2563eb; color:#fff; padding:7px 14px; border-radius:99px; font-size:12px; font-weight:700;">{{ $nights }} Malam</span>
                  @if(($booking->stay_type ?? 'daily') !== 'daily')
                    <div style="margin-top:6px; display:inline-block; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:3px 10px; border-radius:99px; font-size:10px; font-weight:700;">Paket {{ $booking->stay_label }}</div>
                  @endif
                </td>
                <td width="40%" style="padding:18px 18px; text-align:center; vertical-align:top;">
                  <div style="font-size:10px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:1px;">Check-out</div>
                  <div style="font-size:15px; font-weight:700; color:#0f1e3d; margin-top:6px;">{{ $checkOut }}</div>
                  <div style="font-size:10px; color:#94a3b8; margin-top:3px;">sebelum 12:00 WIB</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Detail Akomodasi -->
        <tr>
          <td style="padding:26px 36px 0;">
            <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Detail Akomodasi</div>
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b; vertical-align:top; width:42%;">Properti</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:600; text-align:right;">{{ $hotel->name ?? '-' }}</td>
              </tr>
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b;">Tipe Kamar</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:600; text-align:right;">{{ $room->name ?? '-' }}@if(!empty($room->type)) ({{ ucfirst($room->type) }})@endif</td>
              </tr>
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b;">Jumlah Kamar</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:600; text-align:right;">{{ $booking->room_count ?? 1 }} Kamar</td>
              </tr>
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b;">Jumlah Tamu</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:600; text-align:right;">{{ $booking->guests }} Tamu</td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Data Tamu -->
        <tr>
          <td style="padding:22px 36px 0;">
            <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Data Tamu</div>
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b; width:42%;">Nama</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:600; text-align:right;">{{ $booking->guest_name }}</td>
              </tr>
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b;">Email</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:500; text-align:right;">{{ $booking->guest_email }}</td>
              </tr>
              @if($booking->guest_phone)
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b;">Telepon</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:500; text-align:right;">{{ $booking->guest_phone }}</td>
              </tr>
              @endif
              @if($booking->notes)
              <tr>
                <td style="padding:6px 0; font-size:13px; color:#64748b; vertical-align:top;">Catatan / Permintaan</td>
                <td style="padding:6px 0; font-size:13px; color:#1e293b; font-weight:500; text-align:right;">{{ $booking->notes }}</td>
              </tr>
              @endif
            </table>
          </td>
        </tr>

        <!-- Rincian Pendapatan Mitra -->
        <tr>
          <td style="padding:24px 36px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px;">
              <tr>
                <td style="padding:16px 20px 4px;">
                  <div style="font-size:12px; color:#15803d; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Rincian Pendapatan Mitra</div>
                </td>
              </tr>
              <tr>
                <td style="padding:6px 20px 16px;">
                  <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                      <td style="padding:7px 0; font-size:13px; color:#475569; border-bottom:1px solid #dcfce7;">Harga Kamar{!! $priceSuffix ? ' <span style="color:#94a3b8; font-size:11px;">' . e(trim($priceSuffix)) . '</span>' : '' !!}</td>
                      <td style="padding:7px 0; font-size:13px; color:#1e293b; font-weight:600; text-align:right; border-bottom:1px solid #dcfce7;">{{ $priceBaseRp }}</td>
                    </tr>
                    @if($commissionRp !== null)
                    <tr>
                      <td style="padding:7px 0; font-size:13px; color:#475569; border-bottom:1px solid #dcfce7;">Komisi ArahInn{{ $commissionPctText }}</td>
                      <td style="padding:7px 0; font-size:13px; color:#dc2626; font-weight:600; text-align:right; border-bottom:1px solid #dcfce7;">− {{ $commissionRp }}</td>
                    </tr>
                    @endif
                    <tr>
                      <td style="padding:12px 0 4px; font-size:14px; font-weight:700; color:#15803d;">Pendapatan Anda</td>
                      <td style="padding:12px 0 4px; font-size:20px; font-weight:800; color:#15803d; text-align:right; letter-spacing:-0.3px;">{{ $ownerPayoutRp }}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- PDF Notice -->
        <tr>
          <td style="padding:22px 36px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#fff7ed; border:1px solid #fed7aa; border-radius:10px;">
              <tr>
                <td style="padding:14px 18px;">
                  <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                      <td width="44" style="vertical-align:top;">
                        <div style="width:36px; height:36px; background:#f97316; border-radius:8px; color:#fff; font-weight:700; font-size:11px; text-align:center; line-height:36px;">PDF</div>
                      </td>
                      <td style="vertical-align:top;">
                        <div style="font-size:13px; font-weight:700; color:#7c2d12; margin-bottom:2px;">E-Voucher Mitra Terlampir</div>
                        <div style="font-size:12px; color:#9a3412; line-height:1.5;">File <strong>E-Voucher-Mitra-{{ $booking->booking_code }}.pdf</strong> berisi detail reservasi &amp; rincian pendapatan Anda, sudah dilampirkan di email ini.</div>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Info Mitra -->
        <tr>
          <td style="padding:22px 36px 0;">
            <div style="font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">Yang Perlu Anda Lakukan</div>
            @foreach([
              'Pastikan kamar sudah siap sebelum tanggal check-in tamu.',
              'Terima &amp; cocokkan E-Voucher tamu dengan kartu identitas saat check-in.',
              '<strong>Pendapatan Anda</strong> adalah jumlah bersih setelah dipotong komisi ArahInn (sudah termasuk PPh 2%).',
              'Dana diteruskan ke rekening mitra sesuai jadwal pencairan yang berlaku.',
            ] as $i => $step)
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:6px;">
              <tr>
                <td width="34" style="vertical-align:top; padding-top:3px;">
                  <span style="display:inline-block; width:22px; height:22px; background:#2563eb; color:#fff; border-radius:50%; text-align:center; font-size:11px; font-weight:700; line-height:22px;">{{ $i + 1 }}</span>
                </td>
                <td style="padding:4px 0; font-size:13px; color:#475569; line-height:1.6;">{!! $step !!}</td>
              </tr>
            </table>
            @endforeach
          </td>
        </tr>

        <!-- Spacer bawah -->
        <tr>
          <td style="padding:14px 36px 36px;"></td>
        </tr>

      </table>

      <!-- Footer -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px; width:100%; margin-top:18px;">
        <tr>
          <td style="text-align:center; padding:16px; font-size:11px; color:#94a3b8; line-height:1.7;">
            Email ini dikirim otomatis oleh <strong style="color:#1d4ed8;">My ArahInn</strong>.<br>
            Butuh bantuan kemitraan? Email <a href="mailto:cs@arahinn.com" style="color:#2563eb;">cs@arahinn.com</a>.<br><br>
            &copy; {{ date('Y') }} My ArahInn · Semua hak dilindungi.
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>

</body>
</html>
