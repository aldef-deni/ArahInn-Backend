<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial, Helvetica, sans-serif; color:#1e293b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,0.06);">
        <!-- Header -->
        <tr><td style="background:linear-gradient(135deg,#0f1e3d 0%,#1d4ed8 100%); padding:28px 28px 24px; text-align:center;">
          <img src="{{ $frontendUrl }}/logo-arahin.png" alt="ArahInn" width="120" style="height:auto; display:block; margin:0 auto 10px;">
          <div style="font-size:11px; color:rgba(255,255,255,0.85); letter-spacing:1.5px; text-transform:uppercase;">Bukti Transaksi</div>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:28px;">
          <h1 style="font-size:18px; margin:0 0 6px;">Transaksi berhasil ✅</h1>
          <p style="font-size:13px; color:#64748b; margin:0 0 20px; line-height:1.6;">
            Pembayaran <strong>{{ $trx->product_name }}</strong> untuk <strong>{{ $trx->customer_number }}</strong> telah berhasil. Struk lengkap (PDF) terlampir.
          </p>

          @if($trx->serial_number)
          <div style="background:#1e293b; border-radius:10px; padding:16px 18px; text-align:center; margin-bottom:20px;">
            <div style="font-size:10px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px;">Nomor Seri / Token</div>
            <div style="font-size:20px; font-weight:bold; color:#ffffff; font-family:'Courier New',monospace; letter-spacing:1px; margin-top:5px; word-break:break-all;">{{ $trx->serial_number }}</div>
          </div>
          @endif

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            <tr><td style="padding:9px 0; color:#64748b;">Produk</td><td style="padding:9px 0; text-align:right; font-weight:bold;">{{ $trx->product_name }}</td></tr>
            <tr><td style="padding:9px 0; color:#64748b; border-top:1px solid #f1f5f9;">Nomor Tujuan</td><td style="padding:9px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">{{ $trx->customer_number }}</td></tr>
            <tr><td style="padding:9px 0; color:#64748b; border-top:1px solid #f1f5f9;">Kode Transaksi</td><td style="padding:9px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">{{ $trx->trx_code }}</td></tr>
            <tr><td style="padding:9px 0; color:#64748b; border-top:1px solid #f1f5f9;">Tanggal</td><td style="padding:9px 0; text-align:right; font-weight:bold; border-top:1px solid #f1f5f9;">{{ $issuedAt }}</td></tr>
            <tr><td style="padding:12px 0; color:#1e293b; font-weight:bold; border-top:1px solid #e2e8f0;">Total Bayar</td><td style="padding:12px 0; text-align:right; font-weight:bold; font-size:17px; color:#1d4ed8; border-top:1px solid #e2e8f0;">Rp {{ $totalAmount }}</td></tr>
          </table>

          <p style="font-size:12px; color:#94a3b8; margin:20px 0 0; line-height:1.6;">
            📎 <strong>Struk PDF terlampir.</strong> Simpan sebagai bukti pembayaran yang sah.
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#f8fafc; padding:18px 28px; text-align:center; font-size:11px; color:#94a3b8; line-height:1.6;">
          Butuh bantuan? <a href="mailto:cs@arahinn.com" style="color:#1d4ed8; text-decoration:none;">cs@arahinn.com</a><br>
          © {{ date('Y') }} ArahInn · Travel & Lifestyle Super App
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
