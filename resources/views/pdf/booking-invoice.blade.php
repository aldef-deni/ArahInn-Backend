<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Invoice / Bukti Transaksi</title>
<style>
  @page { margin: 20mm 16mm; size: A4 portrait; }
  body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; line-height: 1.5; margin: 0; }
  .muted { color: #64748b; }
  table { border-collapse: collapse; }

  .top { width: 100%; margin-bottom: 18px; }
  .top td { vertical-align: top; }
  .logo { height: 34px; }
  .doc-sub { font-size: 13px; font-weight: bold; color: #0284c7; margin-top: 4px; }
  .order-badge { background: #0284c7; color: #fff; border-radius: 999px; padding: 5px 14px; font-size: 11px; font-weight: bold; display: inline-block; }
  .paid { display: inline-block; margin-top: 8px; border: 2px solid #16a34a; color: #16a34a; font-weight: bold; font-size: 13px; letter-spacing: 1px; padding: 4px 14px; border-radius: 6px; transform: rotate(-4deg); }

  .section-title { font-size: 13px; font-weight: bold; color: #1e293b; margin: 16px 0 8px; }
  .kv { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; }
  .kv td { padding: 10px 12px; vertical-align: top; }
  .kv .k { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
  .kv .v { font-size: 12px; font-weight: bold; color: #1e293b; margin-top: 2px; }

  table.items { width: 100%; margin-top: 6px; }
  table.items th { text-align: left; font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px; border-bottom: 2px solid #e2e8f0; }
  table.items th.r, table.items td.r { text-align: right; }
  table.items td { font-size: 11px; padding: 10px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  table.items .desc-main { font-weight: bold; }
  table.items .desc-sub { font-size: 9px; color: #94a3b8; margin-top: 2px; }

  .totals { width: 60%; margin-left: 40%; margin-top: 10px; }
  .totals td { padding: 5px 8px; font-size: 11px; }
  .totals td.r { text-align: right; }
  .totals .grand td { border-top: 2px solid #e2e8f0; padding-top: 10px; font-size: 16px; font-weight: bold; color: #f97316; }

  .tax-note { font-size: 9px; color: #94a3b8; margin-top: 6px; }

  .company { margin-top: 22px; border-top: 1px solid #e2e8f0; padding-top: 14px; }
  .company table { width: 100%; }
  .company td { vertical-align: top; padding: 2px 0; }
  .company .k { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
  .company .v { font-size: 11px; font-weight: bold; color: #1e293b; }

  .footer { margin-top: 18px; font-size: 9px; color: #94a3b8; line-height: 1.6; }
</style>
</head>
<body>
  @php
    $logoPaths = [public_path('logo-arahin.png'), public_path('logo-arahinn.png'), public_path('logo.png')];
    $logo = null;
    foreach ($logoPaths as $p) { if (is_file($p)) { $logo = 'data:image/png;base64,' . base64_encode(file_get_contents($p)); break; } }
  @endphp

  <table class="top">
    <tr>
      <td style="width:60%;">
        @if($logo)<img src="{{ $logo }}" class="logo" alt="ArahInn">@else<div style="font-size:22px;font-weight:bold;color:#0284c7;">ArahInn</div>@endif
        <div class="doc-sub">Bukti Transaksi / Invoice</div>
      </td>
      <td style="width:40%; text-align:right;">
        <span class="order-badge">Order ID: {{ $orderId }}</span><br>
        @if($isPaid)<span class="paid">LUNAS / PAID</span>@endif
      </td>
    </tr>
  </table>

  <div class="section-title">Detail Kontak</div>
  <table class="kv">
    <tr>
      <td style="width:34%;"><div class="k">Nama</div><div class="v">{{ $contactName }}</div></td>
      <td style="width:38%;"><div class="k">Alamat Email</div><div class="v">{{ $contactEmail }}</div></td>
      <td style="width:28%;"><div class="k">Nomor Telepon</div><div class="v">{{ $contactPhone }}</div></td>
    </tr>
  </table>

  <div class="section-title">Detail Pembayaran</div>
  <table class="kv">
    <tr>
      <td style="width:50%;"><div class="k">Waktu Pembayaran</div><div class="v">{{ $paidAt }}</div></td>
      <td style="width:50%;"><div class="k">Metode Pembayaran</div><div class="v">{{ $method }}</div></td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr>
        <th style="width:5%;">No</th>
        <th style="width:22%;">Jenis Produk</th>
        <th>Deskripsi</th>
        <th class="r" style="width:24%;">Jumlah</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>1</td>
        <td>Akomodasi</td>
        <td><div class="desc-main">{{ $itemDesc }}</div><div class="desc-sub">{{ $itemSub }}</div></td>
        <td class="r">{{ $basePrice }}</td>
      </tr>
    </tbody>
  </table>

  <table class="totals">
    <tr><td>Subtotal</td><td class="r">{{ $basePrice }}</td></tr>
    @if($promoDisc)<tr><td>Diskon Promo</td><td class="r" style="color:#16a34a;">- {{ $promoDisc }}</td></tr>@endif
    @if($loyaltyDisc)<tr><td>Diskon Poin Loyalti</td><td class="r" style="color:#16a34a;">- {{ $loyaltyDisc }}</td></tr>@endif
    <tr><td>Pajak &amp; Layanan</td><td class="r">{{ $markupTax }}</td></tr>
    <tr class="grand"><td>Total Pembayaran</td><td class="r">{{ $grandTotal }}</td></tr>
  </table>
  @if(!empty($showTaxNote) && !empty($company['tax_note']))
  <div class="tax-note">{{ $company['tax_note'] }}</div>
  @endif

  @if(!empty($company['show_legal']))
  <div class="company">
    <table>
      <tr>
        <td style="width:40%;"><div class="k">Nama Perusahaan</div><div class="v">{{ $company['name'] }}</div></td>
        <td style="width:35%;"><div class="k">NPWP</div><div class="v">{{ $company['npwp'] }}</div></td>
        <td style="width:25%;"><div class="k">Telepon</div><div class="v">{{ $company['phone'] }}</div></td>
      </tr>
    </table>
  </div>
  @endif

  <div class="footer">
    @if(!empty($company['show_legal'])){{ $company['address'] }}<br>@endif
    Customer Care: {{ $company['phone'] }} · {{ $company['email'] }} ({{ $company['cs_hours'] }}) · Dokumen dibuat otomatis, tanpa tanda tangan basah.<br>
    Dicetak: {{ $issuedAt }}
  </div>
</body>
</html>
