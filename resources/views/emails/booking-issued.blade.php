<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Dikonfirmasi – {{ $booking->booking_code }}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #f0f4f8; font-family: 'Inter', Arial, sans-serif; color: #1a202c; }
  a { color: inherit; text-decoration: none; }
  .wrapper { max-width: 620px; margin: 32px auto; padding: 0 16px 48px; }

  /* Header */
  .header { background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); border-radius: 16px 16px 0 0; padding: 32px 36px; text-align: center; }
  .logo-text { font-size: 26px; font-weight: 700; color: #fff; letter-spacing: -0.5px; }
  .logo-sub  { font-size: 11px; color: rgba(255,255,255,0.7); margin-top: 2px; letter-spacing: 1.5px; text-transform: uppercase; }

  /* Confirmed banner */
  .confirmed-badge { background: #22c55e; color: #fff; display: inline-flex; align-items: center; gap: 6px; border-radius: 99px; padding: 6px 18px; font-size: 13px; font-weight: 600; margin-top: 20px; }

  /* Body card */
  .card { background: #fff; border-radius: 0 0 16px 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.07); }

  /* Hero dates */
  .dates-row { display: flex; align-items: center; justify-content: center; gap: 0; background: #f8faff; border-bottom: 1px solid #e8edf5; padding: 28px 24px; }
  .date-box { flex: 1; text-align: center; }
  .date-label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 6px; }
  .date-value { font-size: 16px; font-weight: 700; color: #1e3a5f; }
  .nights-pill { background: #2563eb; color: #fff; border-radius: 99px; padding: 8px 16px; font-size: 13px; font-weight: 600; white-space: nowrap; margin: 0 12px; }

  /* Section */
  .section { padding: 24px 32px; border-bottom: 1px solid #f1f5f9; }
  .section:last-child { border-bottom: none; }
  .section-title { font-size: 13px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 16px; }

  /* Booking code highlight */
  .booking-code-box { background: #eff6ff; border: 1.5px dashed #2563eb; border-radius: 10px; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
  .booking-code-label { font-size: 12px; color: #64748b; }
  .booking-code-value { font-size: 20px; font-weight: 700; color: #2563eb; letter-spacing: 2px; font-family: 'Courier New', monospace; }

  /* Info rows */
  .info-row { display: flex; justify-content: space-between; align-items: flex-start; padding: 8px 0; border-bottom: 1px solid #f8fafc; }
  .info-row:last-child { border-bottom: none; }
  .info-key { font-size: 13px; color: #64748b; flex: 1; }
  .info-val { font-size: 13px; color: #1a202c; font-weight: 500; text-align: right; flex: 1; }

  /* Price breakdown */
  .price-row { display: flex; justify-content: space-between; padding: 7px 0; font-size: 13px; }
  .price-row.muted { color: #64748b; }
  .price-row.discount { color: #16a34a; }
  .price-row.total { border-top: 2px solid #e2e8f0; margin-top: 8px; padding-top: 12px; font-weight: 700; font-size: 15px; color: #1e3a5f; }
  .price-row.total .price-amount { color: #2563eb; font-size: 18px; }

  /* Steps */
  .steps { list-style: none; }
  .steps li { display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; font-size: 13px; color: #475569; }
  .step-num { width: 22px; height: 22px; min-width: 22px; background: #2563eb; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; margin-top: 1px; }

  /* Policy */
  .policy-item { display: flex; gap: 10px; padding: 6px 0; font-size: 13px; color: #475569; }
  .policy-dot { color: #f59e0b; font-size: 16px; margin-top: 1px; }

  /* CTA button */
  .cta-wrap { text-align: center; padding: 28px 32px; }
  .cta-btn { display: inline-block; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff !important; font-size: 14px; font-weight: 600; padding: 14px 36px; border-radius: 10px; }

  /* Footer */
  .footer { text-align: center; padding: 24px 16px 0; }
  .footer p { font-size: 11px; color: #94a3b8; line-height: 1.7; }
  .footer a { color: #2563eb; text-decoration: underline; }
</style>
</head>
<body>
<div class="wrapper">

  {{-- ── Header ── --}}
  <div class="header">
    <img src="{{ $frontendUrl }}/logo-arahin.png" alt="Arahinn.com" style="height:48px;width:auto;display:block;margin:0 auto;">
    <div class="logo-sub" style="margin-top:8px;">Accommodation · Transportation · Activities</div>
    <div>
      <span class="confirmed-badge">
        &#10003; &nbsp;Booking Dikonfirmasi
      </span>
    </div>
  </div>

  <div class="card">

    {{-- ── Check-in / Check-out ── --}}
    <div class="dates-row">
      <div class="date-box">
        <div class="date-label">Check-in</div>
        <div class="date-value">{{ $checkIn }}</div>
      </div>
      <div class="nights-pill">{{ $nights }} Malam</div>
      <div class="date-box">
        <div class="date-label">Check-out</div>
        <div class="date-value">{{ $checkOut }}</div>
      </div>
    </div>

    {{-- ── Booking Code ── --}}
    <div class="section">
      <div class="booking-code-box">
        <div>
          <div class="booking-code-label">Kode Booking</div>
          <div class="booking-code-value">{{ $booking->booking_code }}</div>
        </div>
        <div style="font-size:11px;color:#64748b;text-align:right;">
          Dipesan pada<br>
          <strong>{{ $booking->created_at->setTimezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB</strong>
        </div>
      </div>

      {{-- Hotel info --}}
      <div class="info-row">
        <span class="info-key">Properti</span>
        <span class="info-val">{{ $hotel->name ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-key">Alamat</span>
        <span class="info-val">{{ $hotel->address ?? '' }}{{ $hotel->city ? ', ' . $hotel->city : '' }}</span>
      </div>
      <div class="info-row">
        <span class="info-key">Tipe Kamar</span>
        <span class="info-val">{{ $room->name ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-key">Jumlah Tamu</span>
        <span class="info-val">{{ $booking->guests }} Tamu</span>
      </div>
    </div>

    {{-- ── Data Tamu ── --}}
    <div class="section">
      <div class="section-title">Data Tamu</div>
      <div class="info-row">
        <span class="info-key">Nama</span>
        <span class="info-val">{{ $booking->guest_name }}</span>
      </div>
      <div class="info-row">
        <span class="info-key">Email</span>
        <span class="info-val">{{ $booking->guest_email }}</span>
      </div>
      @if($booking->guest_phone)
      <div class="info-row">
        <span class="info-key">Telepon</span>
        <span class="info-val">{{ $booking->guest_phone }}</span>
      </div>
      @endif
    </div>

    {{-- ── Rincian Pembayaran ── --}}
    <div class="section">
      <div class="section-title">Rincian Pembayaran</div>
      <div class="price-row muted">
        <span>Harga kamar ({{ $nights }} malam)</span>
        <span>Rp {{ $basePrice }}</span>
      </div>
      <div class="price-row muted">
        <span>Biaya layanan platform (12%)</span>
        <span>Rp {{ $markupAmt }}</span>
      </div>
      <div class="price-row muted">
        <span>PPN (11%)</span>
        <span>Rp {{ $taxAmt }}</span>
      </div>
      @if((float)$booking->promo_discount > 0)
      <div class="price-row discount">
        <span>Diskon promo</span>
        <span>− Rp {{ $promoDisc }}</span>
      </div>
      @endif
      @if((float)$booking->loyalty_discount > 0)
      <div class="price-row discount">
        <span>Diskon poin loyalitas</span>
        <span>− Rp {{ $loyaltyDisc }}</span>
      </div>
      @endif
      @if($priceSuffix > 0)
      <div class="price-row muted">
        <span>Kode unik transfer</span>
        <span>+ {{ $priceSuffix }}</span>
      </div>
      @endif
      <div class="price-row total">
        <span>Total Dibayar</span>
        <span class="price-amount">Rp {{ $totalPrice }}</span>
      </div>
    </div>

    {{-- ── Cara Check-in ── --}}
    <div class="section">
      <div class="section-title">Cara Check-in</div>
      <ul class="steps">
        <li>
          <span class="step-num">1</span>
          <span>Tunjukkan <strong>kode booking {{ $booking->booking_code }}</strong> kepada resepsionis saat check-in.</span>
        </li>
        <li>
          <span class="step-num">2</span>
          <span>Bawa kartu identitas (KTP/Paspor) yang sesuai dengan nama tamu.</span>
        </li>
        <li>
          <span class="step-num">3</span>
          <span>Check-in mulai pukul <strong>14:00 WIB</strong> · Check-out sebelum <strong>12:00 WIB</strong>.</span>
        </li>
        <li>
          <span class="step-num">4</span>
          <span>Simpan email ini sebagai bukti reservasi Anda.</span>
        </li>
      </ul>
    </div>

    {{-- ── Kebijakan Pembatalan ── --}}
    <div class="section">
      <div class="section-title">Kebijakan Pembatalan</div>
      <div class="policy-item">
        <span class="policy-dot">&#9679;</span>
        <span>Pembatalan yang dilakukan kurang dari 1 hari sebelum check-in akan dikenakan biaya 100% dari total harga menginap.</span>
      </div>
      <div class="policy-item">
        <span class="policy-dot">&#9679;</span>
        <span>Jika tamu tidak hadir pada tanggal check-in (no-show), akan dikenakan biaya pembatalan sebesar 100%.</span>
      </div>
    </div>

    {{-- ── CTA ── --}}
    <div class="cta-wrap">
      <a href="{{ $frontendUrl }}/orders" class="cta-btn">Lihat Detail Pesanan Saya</a>
    </div>

  </div>

  {{-- ── Footer ── --}}
  <div class="footer">
    <p>
      Email ini dikirim otomatis oleh <strong>Arahinn.com</strong>.<br>
      Jika ada pertanyaan, hubungi kami di <a href="mailto:support@arahinn.com">support@arahinn.com</a><br><br>
      &copy; {{ date('Y') }} Arahinn.com &nbsp;·&nbsp; Semua hak dilindungi.
    </p>
  </div>

</div>
</body>
</html>
