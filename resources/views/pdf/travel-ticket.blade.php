<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>E-Tiket {{ $b->code }}</title>
<style>
  @page { margin: 20mm 16mm; size: A4 portrait; }
  body { font-family: DejaVu Sans, sans-serif; color: #1a202c; font-size: 11px; line-height: 1.5; margin: 0; }
  .brand { color: #0e7490; font-weight: bold; }
  .muted { color: #64748b; }
  .small { font-size: 10px; }

  .header { border-bottom: 3px solid {{ $accent }}; padding-bottom: 14px; margin-bottom: 20px; }
  .header table { width: 100%; border-collapse: collapse; }
  .header .logo { font-size: 24px; font-weight: bold; color: {{ $accent }}; letter-spacing: -0.5px; }
  .header .tagline { color: #94a3b8; font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }
  .header .doc-title { font-size: 19px; font-weight: bold; color: #1e293b; text-align: right; }
  .header .doc-sub { font-size: 10px; color: #64748b; text-align: right; margin-top: 2px; }

  .status-badge { display: inline-block; background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 999px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }

  .code-box { background: {{ $accentSoft }}; border: 1.5px dashed {{ $accent }}; border-radius: 6px; padding: 12px 18px; margin-bottom: 18px; }
  .code-box table { width: 100%; }
  .code-box .label { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
  .code-box .code { font-size: 20px; font-weight: bold; color: {{ $accent }}; letter-spacing: 2px; font-family: 'Courier New', monospace; }

  /* Boarding pass route */
  .route { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
  .route td { vertical-align: middle; }
  .route .time { font-size: 22px; font-weight: bold; color: #1e293b; }
  .route .place { font-size: 11px; color: #475569; }
  .route .mid { text-align: center; color: #94a3b8; font-size: 9px; }
  .route .line { border-top: 1.5px dotted #cbd5e1; margin: 6px 4px 2px; }

  .section { margin-bottom: 16px; }
  .section-title { font-size: 11px; font-weight: bold; color: #475569; text-transform: uppercase; letter-spacing: 0.8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 10px; }

  table.data { width: 100%; border-collapse: collapse; }
  table.data th { text-align: left; font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
  table.data td { font-size: 11px; padding: 7px 8px; border-bottom: 1px solid #f1f5f9; }

  .info-grid { width: 100%; border-collapse: collapse; }
  .info-grid td { width: 50%; padding: 4px 0; vertical-align: top; }
  .info-grid .k { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
  .info-grid .v { font-size: 12px; font-weight: bold; color: #1e293b; }

  .total-box { background: #f8fafc; border-radius: 6px; padding: 12px 16px; margin-top: 8px; }
  .total-box table { width: 100%; }
  .total-box .lbl { font-size: 11px; color: #475569; }
  .total-box .amt { font-size: 18px; font-weight: bold; color: {{ $accent }}; text-align: right; }

  .footer { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 9px; color: #94a3b8; line-height: 1.6; }
</style>
</head>
<body>
  @php
    $logoPaths = [public_path('logo-arahin.png'), public_path('logo-arahinn.png'), public_path('logo.png')];
    $logoBase64 = null;
    foreach ($logoPaths as $p) { if (is_file($p)) { $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($p)); break; } }
  @endphp
  <div class="header">
    <table>
      <tr>
        <td style="vertical-align: top;">
          @if ($logoBase64)
            <img src="{{ $logoBase64 }}" alt="ArahInn" style="height:40px; width:auto; display:block;">
          @else
            <div class="logo">ArahInn</div>
            <div class="tagline">Travel &amp; Lifestyle Super App</div>
          @endif
        </td>
        <td>
          <div class="doc-title">E-TIKET {{ strtoupper($modaLabel) }}</div>
          <div class="doc-sub">{{ $issuedAt }}</div>
        </td>
      </tr>
    </table>
  </div>

  <div style="margin-bottom:14px;">
    <span class="status-badge">{{ $statusLabel }}</span>
  </div>

  <div class="code-box">
    <table>
      <tr>
        <td>
          <div class="label">Kode Booking ArahInn</div>
          <div class="code">{{ $b->code }}</div>
        </td>
        <td style="text-align:right;">
          <div class="label">Kode Booking Maskapai/Operator</div>
          <div class="code" style="font-size:16px;">{{ $b->vendor_booking_code ?: '—' }}</div>
        </td>
      </tr>
    </table>
  </div>

  <table class="route">
    <tr>
      <td style="width:34%;">
        <div class="time">{{ $departTime ?: '—' }}</div>
        <div class="place">{{ $originName }}</div>
      </td>
      <td style="width:32%;">
        <div class="mid">{{ $modaLabel }}</div>
        <div class="line"></div>
        <div class="mid">{{ $departDate }}</div>
      </td>
      <td style="width:34%; text-align:right;">
        <div class="time">{{ $arriveTime ?: '—' }}</div>
        <div class="place">{{ $destinationName }}</div>
      </td>
    </tr>
  </table>

  <div class="section">
    <div class="section-title">Detail Perjalanan</div>
    <table class="info-grid">
      <tr>
        <td><div class="k">{{ $serviceLabel }}</div><div class="v">{{ $b->service_name ?: '—' }}</div></td>
        <td><div class="k">Kelas</div><div class="v">{{ $b->class ?: '—' }}</div></td>
      </tr>
      <tr>
        <td><div class="k">Tanggal Berangkat</div><div class="v">{{ $departDate }}</div></td>
        <td><div class="k">Jumlah Penumpang</div><div class="v">{{ $b->pax }} orang</div></td>
      </tr>
    </table>
  </div>

  @if(count($pax))
  <div class="section">
    <div class="section-title">Data Penumpang</div>
    <table class="data">
      <thead>
        <tr><th style="width:8%;">No</th><th>Nama</th><th style="width:22%;">Tipe</th><th style="width:28%;">Identitas</th></tr>
      </thead>
      <tbody>
        @foreach($pax as $i => $p)
        <tr>
          <td>{{ $i + 1 }}</td>
          <td>{{ $p['name'] }}</td>
          <td>{{ $p['type'] }}</td>
          <td>{{ $p['id'] ?: '—' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  @endif

  <div class="total-box">
    <table>
      <tr>
        <td class="lbl">Total Pembayaran</td>
        <td class="amt">Rp {{ $totalPrice }}</td>
      </tr>
    </table>
  </div>

  <div class="footer">
    <strong>Penting:</strong> Tunjukkan e-tiket ini (cetak atau di layar) beserta identitas asli saat check-in.
    Tiba di stasiun/bandara/pelabuhan minimal 60–90 menit sebelum keberangkatan.
    E-tiket ini diterbitkan resmi oleh ArahInn bekerja sama dengan operator terkait.<br>
    Butuh bantuan? Hubungi cs@arahinn.com · Dokumen dibuat otomatis, tanpa tanda tangan basah.
  </div>
</body>
</html>
