<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial, Helvetica, sans-serif; color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,0.06);">
        <!-- Header (putih + logo ArahInn · KAI · Rajabiller) -->
        <tr><td style="background:#ffffff; padding:22px 24px 16px; border-bottom:1px solid #e2e8f0;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td style="text-align:left; vertical-align:middle; width:38%;">
                <img src="{{ $frontendUrl }}/logo-arahin.png" alt="ArahInn" height="30" style="height:30px; width:auto; display:block;">
              </td>
              <td style="text-align:center; vertical-align:middle; width:28%;">
                @if($b->moda === 'kereta')<img src="{{ $frontendUrl }}/kai.png" alt="KAI" height="28" style="height:28px; width:auto; display:inline-block;">@endif
              </td>
              <td style="text-align:right; vertical-align:middle; width:34%;">
                @if($b->moda === 'kereta')<img src="{{ $frontendUrl }}/rajabiller.png" alt="Rajabiller" height="24" style="height:24px; width:auto; display:inline-block;">@endif
              </td>
            </tr>
          </table>
          <div style="font-size:11px; color:#64748b; letter-spacing:1.5px; text-transform:uppercase; text-align:center; margin-top:14px;">E-Tiket {{ $modaLabel }}</div>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:28px;">
          <h1 style="font-size:18px; margin:0 0 6px;">E-tiket kamu sudah terbit 🎉</h1>
          <p style="font-size:13px; color:#64748b; margin:0 0 20px; line-height:1.6;">
            Terima kasih sudah memesan di ArahInn. Berikut detail perjalananmu. E-tiket lengkap (PDF) terlampir pada email ini.
          </p>

          <!-- Code -->
          <div style="background:{{ $accentSoft }}; border:1.5px dashed {{ $accent }}; border-radius:10px; padding:14px 18px; margin-bottom:20px;">
            <div style="font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Kode Booking</div>
            <div style="font-size:22px; font-weight:bold; color:{{ $accent }}; letter-spacing:2px; font-family:'Courier New',monospace;">{{ $b->code }}</div>
          </div>

          <!-- Route -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px;">
            <tr>
              <td style="text-align:left;">
                <div style="font-size:22px; font-weight:bold;">{{ $departTime ?: '—' }}</div>
                <div style="font-size:12px; color:#64748b;">{{ $originName }}</div>
              </td>
              <td style="text-align:center; color:#94a3b8; font-size:11px;">→<br>{{ $departDate }}</td>
              <td style="text-align:right;">
                <div style="font-size:22px; font-weight:bold;">{{ $arriveTime ?: '—' }}</div>
                <div style="font-size:12px; color:#64748b;">{{ $destinationName }}</div>
              </td>
            </tr>
          </table>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px; border-top:1px solid #e2e8f0;">
            <tr><td style="padding:10px 0; color:#64748b;">{{ $serviceLabel }}</td><td style="padding:10px 0; text-align:right; font-weight:bold;">{{ !empty($airlineName) ? $airlineName . ' (' . ($b->service_name ?: '') . ')' : ($b->service_name ?: '—') }}</td></tr>
            <tr><td style="padding:10px 0; color:#64748b; border-top:1px solid #f1f5f9;">Kelas</td><td style="padding:10px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">{{ $b->class ?: '—' }}</td></tr>
            <tr><td style="padding:10px 0; color:#64748b; border-top:1px solid #f1f5f9;">Penumpang</td><td style="padding:10px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">{{ $b->pax }} orang</td></tr>
            @php $admFee = (int) $b->admin_fee; $svcFee = max(0, (int) $b->total_price - (int) $b->vendor_price + (int) $b->promo_discount - $admFee); @endphp
            @if($svcFee > 0)
            <tr><td style="padding:10px 0; color:#64748b; border-top:1px solid #f1f5f9;">Biaya Penanganan</td><td style="padding:10px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">Rp {{ number_format($svcFee, 0, ',', '.') }}</td></tr>
            @endif
            @if($admFee > 0)
            <tr><td style="padding:10px 0; color:#64748b; border-top:1px solid #f1f5f9;">Biaya Admin</td><td style="padding:10px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">Rp {{ number_format($admFee, 0, ',', '.') }}</td></tr>
            @endif
            <tr><td style="padding:12px 0; color:#1e293b; font-weight:bold; border-top:1px solid #e2e8f0;">Total</td><td style="padding:12px 0; text-align:right; font-weight:bold; font-size:17px; color:{{ $accent }}; border-top:1px solid #e2e8f0;">Rp {{ $totalPrice }}</td></tr>
          </table>

          @if(!empty($pax))
          <!-- Data Penumpang -->
          <div style="margin-top:22px;">
            <div style="font-size:11px; font-weight:bold; color:#475569; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">Data Penumpang</div>
            @foreach ($pax as $p)
              <div style="border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-bottom:8px;">
                <div style="font-size:13px; font-weight:bold; color:#1e293b;">{{ $p['name'] }} <span style="font-size:11px; color:#94a3b8; font-weight:normal;">· {{ $p['type'] }}</span>@if(!empty($p['seat']))<span style="float:right; font-size:11px; font-weight:bold; color:#ea580c;">{{ $p['seat'] }}</span>@endif</div>
                <div style="font-size:12px; color:#64748b; margin-top:3px;">{{ $p['nationality'] }} · {{ $p['idLabel'] ?? 'NIK' }}: {{ $p['id'] ?: '—' }}@if(empty($p['isForeign']) && !empty($p['passport'])) · Paspor: {{ $p['passport'] }}@endif</div>
                @if(!empty($p['hasPassport']) && (!empty($p['passportCountry']) || !empty($p['passportIssue']) || !empty($p['passportExpiry'])))
                  <div style="font-size:11px; color:#94a3b8; margin-top:2px;">@if(!empty($p['passportCountry']))Penerbit: {{ $p['passportCountry'] }}@endif @if(!empty($p['passportIssue']))· Terbit {{ $p['passportIssue'] }}@endif @if(!empty($p['passportExpiry']))· Berlaku s/d {{ $p['passportExpiry'] }}@endif</div>
                @endif
              </div>
            @endforeach
          </div>
          @endif

          @if(!empty($baggage))
          <div style="margin-top:20px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;">
            <div style="font-size:12px; color:#475569;"><strong style="color:#1e293b;">Ketentuan Bagasi:</strong> {{ $baggage }}</div>
            <div style="margin-top:6px;">
              <span style="display:inline-block; font-size:11px; font-weight:bold; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:3px 8px; margin-right:6px;">Non Refund</span>
              <span style="display:inline-block; font-size:11px; font-weight:bold; color:#dc2626; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:3px 8px;">Non Reschedule</span>
            </div>
          </div>
          @endif

          @if(!empty($flightNotes))
          <div style="margin-top:20px;">
            <div style="font-size:13px; font-weight:bold; color:#1e293b; margin-bottom:6px;">Catatan Penting</div>
            <ol style="font-size:12px; color:#475569; line-height:1.7; padding-left:18px; margin:0;">
              @foreach($flightNotes as $n)
                <li>{{ $n }}</li>
              @endforeach
            </ol>
          </div>
          @endif
          @if(!empty($flightNotesEn))
          <div style="margin-top:16px;">
            <div style="font-size:13px; font-weight:bold; color:#1e293b; margin-bottom:6px;">Important Notes</div>
            <ol style="font-size:12px; color:#475569; line-height:1.7; padding-left:18px; margin:0;">
              @foreach($flightNotesEn as $n)
                <li>{{ $n }}</li>
              @endforeach
            </ol>
          </div>
          @endif

          <p style="font-size:12px; color:#94a3b8; margin:20px 0 0; line-height:1.6;">
            📎 <strong>@if(($b->moda ?? '') === 'pelni')E-Tiket (PDF) terlampir.@else E-Tiket &amp; Invoice (PDF) terlampir.@endif</strong> Tunjukkan E-Tiket saat check-in beserta identitas asli. Datang minimal 60–90 menit sebelum keberangkatan.
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#f8fafc; padding:18px 28px; text-align:center; font-size:11px; color:#94a3b8; line-height:1.6;">
          Butuh bantuan? <a href="mailto:cs@arahinn.com" style="color:{{ $accent }}; text-decoration:none;">cs@arahinn.com</a><br>
          © {{ date('Y') }} ArahInn · Travel & Lifestyle Super App
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
