<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>E-Voucher {{ $booking->booking_code }}</title>
<style>
  @page { margin: 24mm 18mm; size: A4 portrait; }
  body { font-family: DejaVu Sans, sans-serif; color: #1a202c; font-size: 11px; line-height: 1.5; margin: 0; }

  .brand { color: #1d4ed8; font-weight: bold; }
  .muted { color: #64748b; }
  .small { font-size: 10px; }

  /* Header */
  .header {
    border-bottom: 3px solid #1d4ed8;
    padding-bottom: 14px;
    margin-bottom: 22px;
  }
  .header table { width: 100%; border-collapse: collapse; }
  .header .logo { font-size: 24px; font-weight: bold; color: #1d4ed8; letter-spacing: -0.5px; }
  .header .tagline { color: #94a3b8; font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }
  .header .doc-title { font-size: 20px; font-weight: bold; color: #1e293b; text-align: right; }
  .header .doc-sub { font-size: 10px; color: #64748b; text-align: right; margin-top: 2px; }

  .status-badge {
    display: inline-block;
    background: #dcfce7;
    color: #166534;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 9px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  /* Booking code box */
  .code-box {
    background: #eff6ff;
    border: 1.5px dashed #2563eb;
    border-radius: 6px;
    padding: 14px 18px;
    margin-bottom: 20px;
  }
  .code-box .label { font-size: 10px; color: #64748b; margin-bottom: 4px; }
  .code-box .code { font-size: 22px; font-weight: bold; color: #1d4ed8; letter-spacing: 3px; font-family: 'Courier New', monospace; }

  /* Section */
  .section { margin-bottom: 18px; }
  .section-title {
    font-size: 11px;
    font-weight: bold;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 6px;
    margin-bottom: 10px;
  }
  .row { width: 100%; }
  .row td { padding: 4px 0; vertical-align: top; font-size: 11px; }
  .row td.key { color: #64748b; width: 35%; }
  .row td.val { color: #1e293b; font-weight: 600; }

  /* Date box */
  .dates {
    width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 14px;
    background: #f8fafc;
    margin-bottom: 18px;
  }
  .dates td { padding: 4px 8px; text-align: center; vertical-align: middle; }
  .dates .lbl { font-size: 9px; color: #64748b; text-transform: uppercase; letter-spacing: 0.6px; }
  .dates .val { font-size: 13px; font-weight: bold; color: #1e3a5f; margin-top: 3px; }
  .dates .nights { background: #1d4ed8; color: #fff; padding: 6px 14px; border-radius: 99px; font-size: 11px; font-weight: bold; display: inline-block; }

  /* Price table */
  .price { width: 100%; border-collapse: collapse; }
  .price td { padding: 5px 0; font-size: 11px; }
  .price td:last-child { text-align: right; }
  .price tr.muted td { color: #64748b; }
  .price tr.discount td { color: #16a34a; }
  .price tr.total td { border-top: 2px solid #1e293b; padding-top: 10px; margin-top: 6px; font-size: 13px; font-weight: bold; color: #1d4ed8; }

  /* Footer */
  .footer {
    margin-top: 30px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
    text-align: center;
    color: #94a3b8;
    font-size: 9px;
    line-height: 1.6;
  }

  /* Notes / Instructions */
  .instructions {
    background: #fffbeb;
    border-left: 3px solid #f59e0b;
    padding: 12px 14px;
    border-radius: 4px;
    margin-bottom: 18px;
  }
  .instructions ol { margin: 6px 0 0 18px; padding: 0; }
  .instructions li { font-size: 10px; color: #78350f; margin-bottom: 4px; line-height: 1.5; }
  .instructions .title { font-size: 11px; font-weight: bold; color: #78350f; }
</style>
</head>
<body>

  @php
    // Cari logo dari beberapa lokasi (production / dev) — fallback gracefully.
    $logoPaths = [
        public_path('logo-arahin.png'),
        public_path('logo-arahinn.png'),
        public_path('logo.png'),
    ];
    $logoBase64 = null;
    foreach ($logoPaths as $p) {
        if (is_file($p)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($p));
            break;
        }
    }
  @endphp

  {{-- Header --}}
  <div class="header">
    <table>
      <tr>
        <td style="width:55%; vertical-align: top;">
          @if ($logoBase64)
            <img src="{{ $logoBase64 }}" alt="Arahinn" style="height:38px; width:auto; display:block;">
            <div class="tagline" style="margin-top:6px;">Accommodation · Transportation · Activities</div>
          @else
            <div class="logo">ArahInn</div>
            <div class="tagline">Accommodation · Transportation · Activities</div>
          @endif
        </td>
        <td style="width:45%; vertical-align: top;">
          <div class="doc-title">E-VOUCHER</div>
          <div class="doc-sub">
            Diterbitkan: {{ $booking->created_at->setTimezone('Asia/Jakarta')->format('d M Y · H:i') }} WIB
          </div>
          <div style="text-align: right; margin-top: 6px;">
            <span class="status-badge">✓ Terkonfirmasi</span>
          </div>
        </td>
      </tr>
    </table>
  </div>

  {{-- Booking code --}}
  <div class="code-box">
    <table style="width:100%">
      <tr>
        <td>
          <div class="label">Kode Booking</div>
          <div class="code">{{ $booking->booking_code }}</div>
        </td>
        <td style="text-align: right;">
          <div class="label">Status</div>
          <div style="font-weight: bold; color: #166534; font-size: 14px;">DIKONFIRMASI</div>
        </td>
      </tr>
    </table>
  </div>

  {{-- Dates --}}
  <table class="dates">
    <tr>
      <td style="width:38%">
        <div class="lbl">Check-in</div>
        <div class="val">{{ $checkIn }}</div>
        <div class="small muted">Mulai pukul 14:00 WIB</div>
      </td>
      <td style="width:24%">
        <span class="nights">{{ $nights }} Malam</span>
      </td>
      <td style="width:38%">
        <div class="lbl">Check-out</div>
        <div class="val">{{ $checkOut }}</div>
        <div class="small muted">Sebelum pukul 12:00 WIB</div>
      </td>
    </tr>
  </table>

  {{-- Properti --}}
  <div class="section">
    <div class="section-title">Detail Akomodasi</div>
    <table class="row">
      <tr><td class="key">Properti</td><td class="val">{{ $hotel->name ?? '-' }}</td></tr>
      <tr><td class="key">Alamat</td><td class="val">{{ trim(($hotel->address ?? '') . ($hotel->city ? ', ' . $hotel->city : '')) ?: '-' }}</td></tr>
      <tr><td class="key">Tipe Kamar</td><td class="val">{{ $room->name ?? '-' }} ({{ ucfirst($room->type ?? '') }})</td></tr>
      <tr><td class="key">Jumlah Kamar</td><td class="val">{{ $booking->room_count ?? 1 }} Kamar</td></tr>
      <tr><td class="key">Jumlah Tamu</td><td class="val">{{ $booking->guests }} Tamu</td></tr>
      @if(!empty($hotel->property_phone))
      <tr><td class="key">Telepon Properti</td><td class="val">{{ $hotel->property_phone }}</td></tr>
      @endif
    </table>
  </div>

  {{-- Data tamu --}}
  <div class="section">
    <div class="section-title">Data Tamu</div>
    <table class="row">
      <tr><td class="key">Nama</td><td class="val">{{ $booking->guest_name }}</td></tr>
      <tr><td class="key">Email</td><td class="val">{{ $booking->guest_email }}</td></tr>
      @if($booking->guest_phone)
      <tr><td class="key">Telepon</td><td class="val">{{ $booking->guest_phone }}</td></tr>
      @endif
      @if($booking->notes)
      <tr><td class="key">Catatan</td><td class="val">{{ $booking->notes }}</td></tr>
      @endif
    </table>
  </div>

  {{-- Rincian harga --}}
  <div class="section">
    <div class="section-title">Rincian Pembayaran</div>
    <table class="price">
      <tr class="muted"><td>Harga kamar ({{ $nights }} malam × {{ $booking->room_count ?? 1 }} kamar)</td><td>Rp {{ $basePrice }}</td></tr>
      <tr class="muted"><td>Biaya layanan platform (12%)</td><td>Rp {{ $markupAmt }}</td></tr>
      @if((float)$booking->tax_amount > 0)
      <tr class="muted"><td>PPN</td><td>Rp {{ $taxAmt }}</td></tr>
      @endif
      @if((float)$booking->promo_discount > 0)
      <tr class="discount"><td>Diskon promo</td><td>− Rp {{ $promoDisc }}</td></tr>
      @endif
      @if((float)$booking->loyalty_discount > 0)
      <tr class="discount"><td>Diskon poin loyalitas</td><td>− Rp {{ $loyaltyDisc }}</td></tr>
      @endif
      @if($priceSuffix > 0)
      <tr class="muted"><td>Kode unik transfer</td><td>+ {{ $priceSuffix }}</td></tr>
      @endif
      <tr class="total"><td>TOTAL DIBAYAR</td><td>Rp {{ $totalPrice }}</td></tr>
    </table>
  </div>

  {{-- Instructions --}}
  <div class="instructions">
    <div class="title">Cara Check-in</div>
    <ol>
      <li>Tunjukkan dokumen E-Voucher ini (cetak atau digital) kepada resepsionis saat check-in.</li>
      <li>Bawa kartu identitas (KTP/Paspor) yang sesuai dengan nama tamu di voucher.</li>
      <li>Check-in dapat dilakukan mulai pukul <strong>14:00 WIB</strong> dan check-out sebelum <strong>12:00 WIB</strong>.</li>
      <li>Voucher ini berlaku untuk satu kali transaksi dan tidak dapat dipindahtangankan.</li>
    </ol>
  </div>

  {{-- Footer --}}
  <div class="footer">
    Dokumen ini dibuat secara otomatis oleh sistem <strong class="brand">Arahinn.com</strong> · Tidak memerlukan tanda tangan atau stempel.<br>
    Pertanyaan? Hubungi <strong>support@arahinn.com</strong> · &copy; {{ date('Y') }} Arahinn.com — Semua hak dilindungi.
  </div>

</body>
</html>
