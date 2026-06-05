<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>E-Struk {{ $trx->trx_code }}</title>
<style>
  @page { margin: 20mm 16mm; size: A4 portrait; }
  body { font-family: DejaVu Sans, sans-serif; color: #1a202c; font-size: 11px; line-height: 1.5; margin: 0; }
  .muted { color: #64748b; }

  .header { border-bottom: 3px solid #1d4ed8; padding-bottom: 14px; margin-bottom: 20px; }
  .header table { width: 100%; border-collapse: collapse; }
  .header .logo { font-size: 24px; font-weight: bold; color: #1d4ed8; letter-spacing: -0.5px; }
  .header .tagline { color: #94a3b8; font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }
  .header .doc-title { font-size: 19px; font-weight: bold; color: #1e293b; text-align: right; }
  .header .doc-sub { font-size: 10px; color: #64748b; text-align: right; margin-top: 2px; }

  .status-badge { display: inline-block; background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 999px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }

  .receipt { max-width: 420px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0; overflow: hidden; }
  .receipt .top { background: #eff6ff; padding: 16px 20px; text-align: center; border-bottom: 1px dashed #cbd5e1; }
  .receipt .top .prod { font-size: 14px; font-weight: bold; color: #1e293b; }
  .receipt .top .cust { font-size: 12px; color: #475569; margin-top: 3px; }

  .sn-box { background: #1e293b; color: #fff; border-radius: 8px; margin: 16px 20px; padding: 12px 16px; text-align: center; }
  .sn-box .lbl { font-size: 9px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
  .sn-box .sn { font-size: 18px; font-weight: bold; font-family: 'Courier New', monospace; letter-spacing: 1px; margin-top: 4px; word-break: break-all; }

  table.kv { width: 100%; border-collapse: collapse; padding: 0 20px; }
  .kv-wrap { padding: 4px 20px 16px; }
  table.kv td { padding: 6px 0; font-size: 11px; border-bottom: 1px solid #f1f5f9; }
  table.kv td.k { color: #64748b; }
  table.kv td.v { text-align: right; font-weight: bold; color: #1e293b; }

  .total-row td { font-size: 13px !important; padding-top: 10px !important; border-bottom: none !important; }
  .total-row .v { color: #1d4ed8 !important; font-size: 16px !important; }

  .struk-lines { background: #f8fafc; border-radius: 8px; margin: 0 20px 18px; padding: 12px 16px; font-family: 'Courier New', monospace; font-size: 10px; color: #334155; white-space: pre-wrap; line-height: 1.6; }

  .footer { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 9px; color: #94a3b8; line-height: 1.6; text-align: center; }
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
          <div class="doc-title">BUKTI TRANSAKSI</div>
          <div class="doc-sub">{{ $issuedAt }}</div>
        </td>
      </tr>
    </table>
  </div>

  <div style="text-align:center; margin-bottom:14px;">
    <span class="status-badge">{{ $statusLabel }}</span>
  </div>

  <div class="receipt">
    <div class="top">
      <div class="prod">{{ $trx->product_name }}</div>
      <div class="cust">{{ $trx->customer_number }}@if($trx->customer_name) · {{ $trx->customer_name }}@endif</div>
    </div>

    @if($trx->serial_number)
    <div class="sn-box">
      <div class="lbl">Nomor Seri / Token</div>
      <div class="sn">{{ $trx->serial_number }}</div>
    </div>
    @endif

    <div class="kv-wrap">
      <table class="kv">
        <tr><td class="k">Kode Transaksi</td><td class="v">{{ $trx->trx_code }}</td></tr>
        <tr><td class="k">Tanggal</td><td class="v">{{ $issuedAt }}</td></tr>
        @if($trx->raja_biller_ref)<tr><td class="k">Ref ID</td><td class="v">{{ $trx->raja_biller_ref }}</td></tr>@endif
        <tr class="total-row"><td class="k">Total Bayar</td><td class="v">Rp {{ $totalAmount }}</td></tr>
      </table>
    </div>

    @if($strukText)
    <div class="struk-lines">{{ $strukText }}</div>
    @endif
  </div>

  <div class="footer">
    Simpan struk ini sebagai bukti pembayaran yang sah.<br>
    Butuh bantuan? Hubungi cs@arahinn.com · Dokumen dibuat otomatis, tanpa tanda tangan basah.
  </div>
</body>
</html>
