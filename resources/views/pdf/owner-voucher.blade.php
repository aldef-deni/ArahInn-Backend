<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>E-Voucher Mitra {{ $booking->booking_code }}</title>
<style>
  @page { margin: 16mm 14mm; size: A4 portrait; }
  body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; line-height: 1.5; margin: 0; }
  .muted { color: #64748b; }
  .small { font-size: 9.5px; }

  /* Header */
  .head { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .head .title { font-size: 19px; font-weight: bold; color: #0f172a; }
  .logo { font-size: 20px; font-weight: bold; color: #1d4ed8; letter-spacing: -0.5px; text-align: right; }
  .tagline { color: #94a3b8; font-size: 8px; letter-spacing: 1.2px; text-transform: uppercase; text-align: right; margin-top: 2px; }

  .prop-name { font-size: 16px; font-weight: bold; color: #0f172a; margin-top: 14px; }
  .prop-meta { color: #475569; font-size: 10.5px; margin-top: 4px; }

  .badge { display: inline-block; background: #dcfce7; color: #166534; padding: 3px 12px; border-radius: 999px; font-size: 9px; font-weight: bold; letter-spacing: .4px; }
  .ordered { color: #64748b; font-size: 9.5px; }

  .divider { border: none; border-top: 1px solid #e2e8f0; margin: 16px 0; }

  /* Two columns */
  .cols { width: 100%; border-collapse: separate; border-spacing: 0; }
  .cols > tbody > tr > td { vertical-align: top; }
  .col-l { width: 52%; padding-right: 12px; }
  .col-r { width: 48%; padding-left: 12px; }

  .sec-title { font-size: 13px; font-weight: bold; color: #0f172a; margin-bottom: 10px; }

  .card { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
  .card .card-hd { background: #f8faff; padding: 9px 14px; border-bottom: 1px solid #e2e8f0; }
  .card .card-hd .rt { font-weight: bold; font-size: 12px; color: #1e293b; }
  .card .card-bd { padding: 12px 14px; }

  .fld { margin-bottom: 11px; }
  .fld:last-child { margin-bottom: 0; }
  .fld .k { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 2px; }
  .fld .v { font-size: 11.5px; color: #1e293b; font-weight: 600; }

  .itin { display: inline-block; background: #eff6ff; color: #2563eb; padding: 2px 8px; border-radius: 5px; font-size: 9.5px; font-weight: bold; }

  /* dates mini */
  .dates td { vertical-align: top; }
  .dates .lbl { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
  .dates .d { font-weight: bold; font-size: 12px; color: #1e293b; margin-top: 3px; }
  .nights { color: #64748b; font-size: 10px; text-align: center; padding-top: 14px; }

  /* payment rows */
  .pay { width: 100%; border-collapse: collapse; }
  .pay td { padding: 6px 0; font-size: 11px; border-bottom: 1px solid #f1f5f9; }
  .pay td:last-child { text-align: right; font-weight: 600; }
  .pay tr.disc td:last-child { color: #16a34a; }
  .pay tr.comm td:last-child { color: #dc2626; }
  .pay tr.total td { border-top: 2px solid #cbd5e1; border-bottom: none; padding-top: 9px; font-size: 13px; font-weight: bold; }
  .pay tr.total td.payout { color: #15803d; }

  .paid-note { color: #16a34a; font-weight: bold; font-size: 10.5px; margin-top: 8px; text-align: right; }
  .unpaid-note { color: #ea580c; font-weight: bold; font-size: 10.5px; margin-top: 8px; text-align: right; }

  /* policy */
  .policy { margin-top: 4px; }
  .policy .sec-title { margin-bottom: 8px; }
  .policy ol { margin: 0 0 0 16px; padding: 0; }
  .policy li { font-size: 10px; color: #475569; margin-bottom: 4px; line-height: 1.5; }

  .footer { margin-top: 26px; padding-top: 14px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 9px; line-height: 1.6; }
  .footer strong { color: #1d4ed8; }
</style>
</head>
<body>

  @php
    $logoPaths = [public_path('logo-arahin.png'), public_path('logo-arahinn.png'), public_path('logo.png')];
    $logoBase64 = null;
    foreach ($logoPaths as $p) { if (is_file($p)) { $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($p)); break; } }

    $rp = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $addr = trim(($hotel->address ?? '') . ($hotel->city ? ', ' . $hotel->city : ''));
    $isPaid = in_array($booking->status, ['paid', 'issued', 'rescheduled'], true);

    $rateType = !empty($room->type) ? ' (' . ucfirst($room->type) . ')' : '';
    $ratePlanSuffix = (($booking->stay_type ?? 'daily') !== 'daily') ? ' · Paket ' . $booking->stay_label : ' · Room only';
    $ratePlanText = ($room->name ?? '-') . $rateType . $ratePlanSuffix;
  @endphp

  {{-- Header --}}
  <table class="head">
    <tr>
      <td style="width:60%; vertical-align: top;">
        <div class="title">Reservation E-voucher</div>
      </td>
      <td style="width:40%; vertical-align: top;">
        @if ($logoBase64)
          <img src="{{ $logoBase64 }}" alt="ArahInn" style="height:30px; width:auto; display:block; margin-left:auto;">
        @else
          <div class="logo">ArahInn</div>
        @endif
        <div class="tagline">Accommodation · Transportation · Activities</div>
      </td>
    </tr>
  </table>

  <table style="width:100%; border-collapse:collapse;">
    <tr>
      <td style="vertical-align: top;">
        <div class="prop-name">{{ $hotel->name ?? 'Properti' }}</div>
        <div class="prop-meta">{{ $addr ?: '-' }}</div>
        @if(!empty($hotel->property_phone))
          <div class="prop-meta">{{ $hotel->property_phone }}</div>
        @endif
      </td>
      <td style="width:160px; vertical-align: top; text-align:right;">
        <span class="badge">{{ $isPaid ? 'Confirmed' : 'Menunggu Pembayaran' }}</span>
        <div class="ordered" style="margin-top:6px;">
          Ordered on {{ $booking->created_at->setTimezone('Asia/Jakarta')->translatedFormat('d M Y') }} · {{ $booking->created_at->setTimezone('Asia/Jakarta')->format('H:i') }}
        </div>
      </td>
    </tr>
  </table>

  <hr class="divider">

  {{-- Two columns --}}
  <table class="cols">
    <tr>
      {{-- LEFT: Booking Details --}}
      <td class="col-l">
        <div class="sec-title">Booking Details</div>
        <div class="card">
          <div class="card-hd">
            <table style="width:100%"><tr>
              <td class="rt">Room Type 1</td>
              <td style="text-align:right;"><span class="itin">Itinerary ID: {{ $booking->booking_code }}</span></td>
            </tr></table>
          </div>
          <div class="card-bd">
            {{-- Dates --}}
            <table class="dates" style="width:100%; margin-bottom:12px;">
              <tr>
                <td style="width:40%">
                  <div class="lbl">Check-in</div>
                  <div class="d">{{ $checkIn }}</div>
                </td>
                <td style="width:20%"><div class="nights">{{ $nights }} Malam</div></td>
                <td style="width:40%; text-align:right;">
                  <div class="lbl" style="text-align:right;">Check-out</div>
                  <div class="d" style="text-align:right;">{{ $checkOut }}</div>
                </td>
              </tr>
            </table>

            <div class="fld"><div class="k">Room and Rate Plan</div><div class="v">{{ $ratePlanText }}</div></div>
            <div class="fld"><div class="k">Number of Rooms</div><div class="v">{{ $booking->room_count ?? 1 }} Room</div></div>
            <div class="fld"><div class="k">Number of Guests</div><div class="v">{{ $booking->guests }} Tamu</div></div>
            <div class="fld"><div class="k">Guest Name</div><div class="v">Room 1: {{ $booking->guest_name }}</div></div>
            <div class="fld"><div class="k">Special Request</div><div class="v">{{ $booking->notes ?: '-' }}</div></div>
          </div>
        </div>
      </td>

      {{-- RIGHT: Pendapatan Mitra (Payment Details) --}}
      <td class="col-r">
        <div class="sec-title">Rincian Pendapatan Mitra</div>
        <div class="card">
          <div class="card-bd">
            <table class="pay">
              <tr><td class="muted">Harga Kamar{!! $priceSuffix ? '<br><span class="small">' . e(trim($priceSuffix)) . '</span>' : '' !!}</td><td>{{ $rp($priceBase) }}</td></tr>
              @if($commissionNominal !== null)
                <tr class="comm"><td class="muted">Komisi ArahInn{{ $commissionPctText }}</td><td>- {{ $rp($commissionNominal) }}</td></tr>
              @endif
              <tr class="total"><td>Pendapatan Anda</td><td class="payout">{{ $rp($ownerPayout) }}</td></tr>
            </table>
            @if($isPaid)
              <div class="paid-note">✓ Pembayaran tamu lunas</div>
            @else
              <div class="unpaid-note">Menunggu pembayaran tamu</div>
            @endif
          </div>
        </div>

        <div style="height:12px"></div>
        <div class="card">
          <div class="card-hd"><span class="rt">Rincian Tamu</span></div>
          <div class="card-bd">
            <div class="fld"><div class="k">Email Tamu</div><div class="v">{{ $booking->guest_email }}</div></div>
            @if($booking->guest_phone)
              <div class="fld"><div class="k">Telepon Tamu</div><div class="v">{{ $booking->guest_phone }}</div></div>
            @endif
          </div>
        </div>
      </td>
    </tr>
  </table>

  <hr class="divider">

  {{-- Policy --}}
  <div class="policy">
    <div class="sec-title">Informasi Penting untuk Mitra</div>
    <ol>
      <li>Pastikan kamar sudah siap sebelum tanggal check-in tamu.</li>
      <li>Tunjukkan/terima E-Voucher ini saat tamu check-in. Cocokkan nama tamu dengan kartu identitas.</li>
      <li><strong>Pendapatan Anda</strong> adalah jumlah bersih setelah dipotong komisi ArahInn sesuai perjanjian kemitraan.</li>
      <li>Dana akan diteruskan ke rekening mitra sesuai jadwal pencairan yang berlaku.</li>
    </ol>
  </div>

  {{-- Footer --}}
  <div class="footer">
    Dokumen ini dibuat otomatis oleh sistem <strong>Arahinn.com</strong> · Tidak memerlukan tanda tangan atau stempel.<br>
    Pertanyaan kemitraan? Hubungi <strong>cs@arahinn.com</strong> · &copy; {{ date('Y') }} Arahinn.com — Semua hak dilindungi.
  </div>

</body>
</html>
