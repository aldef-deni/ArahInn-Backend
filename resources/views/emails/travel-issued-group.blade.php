<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial, Helvetica, sans-serif; color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,0.06);">
        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#0f1e3d 0%,{{ $accent }} 100%); padding:28px 28px 24px; text-align:center;">
          <img src="{{ $frontendUrl }}/logo-arahin.png" alt="ArahInn" width="120" style="height:auto; display:block; margin:0 auto 10px;">
          <div style="font-size:11px; color:rgba(255,255,255,0.85); letter-spacing:1.5px; text-transform:uppercase;">E-Tiket Pesawat · Pulang-Pergi</div>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:28px;">
          <h1 style="font-size:18px; margin:0 0 6px;">E-tiket pulang-pergi kamu sudah terbit 🎉</h1>
          <p style="font-size:13px; color:#64748b; margin:0 0 20px; line-height:1.6;">
            Terima kasih sudah memesan di ArahInn. Kedua e-tiket (pergi &amp; pulang) terlampir dalam <strong>satu PDF</strong> pada email ini.
          </p>

          @foreach ($legs as $leg)
            @php $b = $leg['b']; @endphp
            <div style="border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; margin-bottom:14px;">
              <div style="display:inline-block; background:{{ $accent }}; color:#fff; font-size:10px; font-weight:bold; text-transform:uppercase; letter-spacing:1px; padding:4px 12px; border-radius:999px; margin-bottom:12px;">{{ $leg['legName'] }}</div>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
                <tr>
                  <td style="text-align:left;">
                    <div style="font-size:20px; font-weight:bold;">{{ $leg['departTime'] ?: '—' }}</div>
                    <div style="font-size:12px; color:#64748b;">{{ $leg['originName'] }}</div>
                  </td>
                  <td style="text-align:center; color:#94a3b8; font-size:11px;">→<br>{{ $leg['departDate'] }}</td>
                  <td style="text-align:right;">
                    <div style="font-size:20px; font-weight:bold;">{{ $leg['arriveTime'] ?: '—' }}</div>
                    <div style="font-size:12px; color:#64748b;">{{ $leg['destinationName'] }}</div>
                  </td>
                </tr>
              </table>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:12.5px; border-top:1px solid #f1f5f9;">
                <tr><td style="padding:7px 0; color:#64748b;">Kode Booking</td><td style="padding:7px 0; text-align:right; font-weight:bold; font-family:'Courier New',monospace;">{{ $b->code }}</td></tr>
                <tr><td style="padding:7px 0; color:#64748b; border-top:1px solid #f1f5f9;">{{ $leg['serviceLabel'] }}</td><td style="padding:7px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">{{ $b->service_name ?: '—' }} · {{ $b->class ?: '—' }}</td></tr>
              </table>
            </div>
          @endforeach

          @php $pax = $legs[0]['pax'] ?? []; @endphp
          @if(!empty($pax))
          <!-- Data Penumpang (sama untuk pergi & pulang) -->
          <div style="margin:8px 0 4px;">
            <div style="font-size:11px; font-weight:bold; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">Data Penumpang</div>
            @foreach ($pax as $p)
              <div style="border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-bottom:8px;">
                <div style="font-size:13px; font-weight:bold; color:#1e293b;">{{ $p['name'] }} <span style="font-size:11px; color:#94a3b8; font-weight:normal;">· {{ $p['type'] }}</span></div>
                <div style="font-size:12px; color:#64748b; margin-top:3px;">{{ $p['nationality'] }} · {{ $p['idLabel'] ?? 'NIK' }}: {{ $p['id'] ?: '—' }}</div>
                @if(!empty($p['isForeign']) && (!empty($p['passportCountry']) || !empty($p['passportIssue']) || !empty($p['passportExpiry'])))
                  <div style="font-size:11px; color:#94a3b8; margin-top:2px;">@if(!empty($p['passportCountry']))Penerbit: {{ $p['passportCountry'] }}@endif @if(!empty($p['passportIssue']))· Terbit {{ $p['passportIssue'] }}@endif @if(!empty($p['passportExpiry']))· Berlaku s/d {{ $p['passportExpiry'] }}@endif</div>
                @endif
              </div>
            @endforeach
          </div>
          @endif

          <!-- Total gabungan -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px; margin-top:4px;">
            <tr><td style="padding:12px 0; color:#1e293b; font-weight:bold; border-top:1px solid #e2e8f0;">Total Pembayaran</td><td style="padding:12px 0; text-align:right; font-weight:bold; font-size:17px; color:{{ $accent }}; border-top:1px solid #e2e8f0;">Rp {{ $groupTotal }}</td></tr>
          </table>

          <p style="font-size:12px; color:#94a3b8; margin:18px 0 0; line-height:1.6;">
            📎 <strong>E-Tiket PDF terlampir (2 halaman: pergi &amp; pulang).</strong> Tunjukkan saat check-in beserta identitas asli. Datang minimal 60–90 menit sebelum keberangkatan.
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#f8fafc; padding:18px 28px; text-align:center; font-size:11px; color:#94a3b8; line-height:1.6;">
          Butuh bantuan? <a href="mailto:cs@arahinn.com" style="color:{{ $accent }}; text-decoration:none;">cs@arahinn.com</a><br>
          © {{ date('Y') }} ArahInn · Travel &amp; Lifestyle Super App
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
