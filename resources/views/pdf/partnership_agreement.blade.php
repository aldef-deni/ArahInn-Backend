<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; line-height: 1.6; }

    .page { padding: 50px 55px; }

    /* ── Header ── */
    .header { text-align: center; border-bottom: 2px solid #1e40af; padding-bottom: 18px; margin-bottom: 24px; }
    .header .brand { font-size: 22px; font-weight: 700; color: #1e40af; letter-spacing: 1px; }
    .header .subtitle { font-size: 10px; color: #64748b; margin-top: 2px; }
    .header .doc-title { font-size: 15px; font-weight: 700; color: #1e293b; margin-top: 14px; letter-spacing: 0.5px; }
    .header .doc-number { font-size: 10px; color: #64748b; margin-top: 3px; }

    /* ── Parties ── */
    .parties-section { margin-bottom: 20px; }
    .parties-section p { font-size: 11px; color: #334155; margin-bottom: 6px; }

    .party-box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin-bottom: 10px; background: #f8fafc; }
    .party-box .party-label { font-size: 9px; font-weight: 700; color: #2563eb; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; }
    .party-box .party-name { font-size: 13px; font-weight: 700; color: #1e293b; }
    .party-box .party-detail { font-size: 10px; color: #64748b; margin-top: 2px; }

    .parties-connector { text-align: center; font-size: 11px; font-weight: 700; color: #475569; padding: 4px 0; }

    /* ── Section ── */
    .section { margin-bottom: 16px; }
    .section-title { font-size: 11px; font-weight: 700; color: #1e40af; margin-bottom: 8px; border-left: 3px solid #2563eb; padding-left: 8px; }
    .section p { margin-bottom: 5px; color: #334155; }

    /* ── Table ── */
    table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10.5px; }
    table.data-table td { padding: 6px 10px; border: 1px solid #e2e8f0; vertical-align: top; }
    table.data-table td:first-child { width: 38%; background: #f8fafc; font-weight: 600; color: #475569; }
    table.data-table td:last-child { color: #1e293b; }

    /* ── Clause ── */
    .clause { margin-bottom: 14px; }
    .clause-title { font-size: 11px; font-weight: 700; color: #1e293b; margin-bottom: 5px; }
    .clause ol { padding-left: 18px; margin: 0; }
    .clause ol li { margin-bottom: 4px; color: #334155; }
    .clause ul { padding-left: 16px; margin: 0; }
    .clause ul li { margin-bottom: 3px; color: #334155; list-style: disc; }

    /* ── Signature ── */
    .signature-section { margin-top: 32px; border-top: 1px solid #e2e8f0; padding-top: 20px; }
    .sig-title { text-align: center; font-size: 11px; color: #475569; margin-bottom: 20px; }
    .sig-row { display: table; width: 100%; }
    .sig-col { display: table-cell; width: 50%; vertical-align: top; padding: 0 10px; }
    .sig-box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px; text-align: center; }
    .sig-box .sig-label { font-size: 10px; color: #64748b; margin-bottom: 4px; }
    .sig-box .sig-entity { font-size: 12px; font-weight: 700; color: #1e293b; margin-bottom: 60px; }
    .sig-box .sig-name { font-size: 11px; font-weight: 700; border-top: 1px solid #cbd5e1; padding-top: 8px; color: #1e293b; }
    .sig-box .sig-position { font-size: 10px; color: #64748b; }

    /* ── Footer ── */
    .doc-footer { margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 10px; text-align: center; font-size: 9px; color: #94a3b8; }

    .highlight { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; }
    .highlight strong { color: #1d4ed8; }
  </style>
</head>
<body>
<div class="page">

  @php
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

  <!-- Header -->
  <div class="header">
    @if ($logoBase64)
      <img src="{{ $logoBase64 }}" alt="Arahinn" style="height:42px; width:auto; margin-bottom:8px;">
    @else
      <div class="brand">ArahInn.com</div>
    @endif
    <div class="subtitle">Platform Pemesanan Akomodasi Terpercaya Indonesia</div>
    <div class="doc-title">PERJANJIAN KEMITRAAN AKOMODASI</div>
    <div class="doc-number">No. PKA/{{ date('Y') }}/{{ str_pad($hotel->id, 5, '0', STR_PAD_LEFT) }} &mdash; {{ $date }}</div>
  </div>

  <!-- Intro -->
  <div class="parties-section">
    <p>Perjanjian ini (<strong>"Perjanjian"</strong>) dibuat dan ditandatangani pada tanggal <strong>{{ $date }}</strong>
    oleh dan antara pihak-pihak yang disebutkan di bawah ini:</p>
  </div>

  <!-- Parties -->
  <div class="party-box">
    <div class="party-label">Pihak Pertama</div>
    <div class="party-name">PT ArahInn Technology Indonesia</div>
    <div class="party-detail">Platform arahinn.com &mdash; Jakarta, Indonesia</div>
    <div class="party-detail">Selanjutnya disebut <strong>"ArahInn"</strong></div>
  </div>

  <div class="parties-connector">— DAN —</div>

  <div class="party-box">
    <div class="party-label">Pihak Kedua</div>
    <div class="party-name">{{ $hotel->name }}</div>
    <div class="party-detail">{{ $hotel->address }}{{ $hotel->city ? ', ' . $hotel->city : '' }}{{ $hotel->province ? ', ' . $hotel->province : '' }}</div>
    <div class="party-detail">Pemilik: {{ $owner->name }} &mdash; {{ $owner->email }}</div>
    <div class="party-detail">Selanjutnya disebut <strong>"Mitra"</strong></div>
  </div>

  <!-- Data Properti -->
  <div class="section" style="margin-top:18px;">
    <div class="section-title">DATA PROPERTI</div>
    <table class="data-table">
      <tr><td>Nama Properti</td><td>{{ $hotel->name }}</td></tr>
      <tr><td>Kategori</td><td>{{ $hotel->category ?? '-' }}</td></tr>
      <tr><td>Alamat</td><td>{{ $hotel->address }}{{ $hotel->city ? ', ' . $hotel->city : '' }}{{ $hotel->province ? ', ' . $hotel->province : '' }}</td></tr>
      <tr><td>Nama Pemilik / PIC</td><td>{{ $owner->name }}</td></tr>
      <tr><td>Email Akun</td><td>{{ $owner->email }}</td></tr>
      <tr><td>Telepon</td><td>{{ $owner->phone ?? '-' }}</td></tr>
      <tr><td>Tanggal Registrasi</td><td>{{ $date }}</td></tr>
    </table>
  </div>

  <!-- Pasal 1 -->
  <div class="clause">
    <div class="clause-title">Pasal 1 &mdash; Definisi</div>
    <ol>
      <li><strong>"Platform"</strong> berarti situs web, aplikasi, dan layanan yang dioperasikan oleh ArahInn.</li>
      <li><strong>"Properti"</strong> berarti akomodasi yang didaftarkan Mitra pada Platform ArahInn.</li>
      <li><strong>"Tamu"</strong> berarti pengguna Platform yang melakukan pemesanan di Properti Mitra.</li>
      <li><strong>"Extranet"</strong> berarti portal manajemen yang disediakan ArahInn untuk Mitra.</li>
    </ol>
  </div>

  <!-- Pasal 2 -->
  <div class="clause">
    <div class="clause-title">Pasal 2 &mdash; Ruang Lingkup Kerja Sama</div>
    <p style="color:#334155;margin-bottom:5px;">ArahInn setuju untuk:</p>
    <ul>
      <li>Mempublikasikan informasi dan ketersediaan Properti Mitra di Platform ArahInn.</li>
      <li>Memproses pemesanan dari Tamu dan meneruskan konfirmasi kepada Mitra.</li>
      <li>Menyediakan akses Extranet untuk Mitra mengelola tarif, ketersediaan, dan informasi properti.</li>
      <li>Memberikan dukungan pelanggan kepada Tamu terkait pemesanan.</li>
    </ul>
  </div>

  <!-- Pasal 3 -->
  <div class="clause">
    <div class="clause-title">Pasal 3 &mdash; Kewajiban Mitra</div>
    <ol>
      <li>Memastikan akurasi informasi properti, termasuk harga, foto, dan fasilitas.</li>
      <li>Mengkonfirmasi pemesanan dari Tamu tepat waktu (maks. 24 jam).</li>
      <li>Memberikan layanan akomodasi sesuai dengan deskripsi yang tercantum di Platform.</li>
      <li>Memperbarui ketersediaan kamar secara berkala melalui Extranet.</li>
      <li>Menjaga standar kebersihan dan pelayanan sesuai kategori properti.</li>
    </ol>
  </div>

  <!-- Pasal 4 -->
  <div class="clause">
    <div class="clause-title">Pasal 4 &mdash; Komisi dan Pembayaran</div>
    <div class="highlight">
      <strong>Komisi:</strong> ArahInn akan mengenakan komisi sebesar yang telah disepakati atas setiap transaksi pemesanan yang berhasil
      melalui Platform. Rincian komisi diatur dalam Lampiran Keuangan yang merupakan bagian tidak terpisahkan dari Perjanjian ini.
    </div>
    <ol>
      <li>Pembayaran dari Tamu dikumpulkan oleh ArahInn dan diteruskan ke Mitra setelah dikurangi komisi.</li>
      <li>Jadwal pembayaran dilakukan sesuai dengan siklus pembayaran yang berlaku di Platform.</li>
      <li>Mitra wajib menyediakan informasi rekening bank yang valid untuk keperluan transfer dana.</li>
    </ol>
  </div>

  <!-- Pasal 5 -->
  <div class="clause">
    <div class="clause-title">Pasal 5 &mdash; Pembatalan dan Kebijakan Refund</div>
    <ol>
      <li>Kebijakan pembatalan mengikuti ketentuan yang ditetapkan Mitra pada saat pendaftaran.</li>
      <li>ArahInn berhak menerapkan kebijakan refund kepada Tamu sesuai ketentuan yang berlaku.</li>
      <li>Mitra tidak dapat mengubah kebijakan pembatalan untuk pemesanan yang sudah terkonfirmasi.</li>
    </ol>
  </div>

  <!-- Pasal 6 -->
  <div class="clause">
    <div class="clause-title">Pasal 6 &mdash; Kerahasiaan</div>
    <p style="color:#334155;">
      Masing-masing pihak wajib menjaga kerahasiaan informasi yang diperoleh dari pihak lain dalam rangka pelaksanaan
      Perjanjian ini dan tidak akan mengungkapkannya kepada pihak ketiga tanpa persetujuan tertulis dari pihak lain,
      kecuali diwajibkan oleh hukum yang berlaku.
    </p>
  </div>

  <!-- Pasal 7 -->
  <div class="clause">
    <div class="clause-title">Pasal 7 &mdash; Jangka Waktu dan Pengakhiran</div>
    <ol>
      <li>Perjanjian ini berlaku sejak tanggal ditandatangani dan berlaku untuk jangka waktu tidak terbatas.</li>
      <li>Salah satu pihak dapat mengakhiri Perjanjian ini dengan memberikan pemberitahuan tertulis 30 hari sebelumnya.</li>
      <li>ArahInn berhak menangguhkan atau mengakhiri Perjanjian seketika jika Mitra melanggar ketentuan Perjanjian ini.</li>
    </ol>
  </div>

  <!-- Pasal 8 -->
  <div class="clause">
    <div class="clause-title">Pasal 8 &mdash; Penyelesaian Sengketa</div>
    <p style="color:#334155;">
      Setiap perselisihan yang timbul dari atau sehubungan dengan Perjanjian ini akan diselesaikan secara musyawarah.
      Apabila tidak tercapai kesepakatan, sengketa diselesaikan melalui Badan Arbitrase Nasional Indonesia (BANI)
      di Jakarta dengan menggunakan Hukum Indonesia sebagai hukum yang berlaku.
    </p>
  </div>

  <!-- Signature -->
  <div class="signature-section">
    <div class="sig-title">
      Perjanjian ini dibuat dalam 2 (dua) rangkap dan ditandatangani oleh para pihak pada tanggal tersebut di atas.
    </div>
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td width="48%" style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;text-align:center;vertical-align:top;">
          <p style="font-size:10px;color:#64748b;margin-bottom:4px;">Pihak Pertama</p>
          <p style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:55px;">PT ArahInn Technology Indonesia</p>
          <p style="font-size:11px;font-weight:700;border-top:1px solid #cbd5e1;padding-top:8px;color:#1e293b;">_________________________</p>
          <p style="font-size:10px;color:#64748b;">Direktur / Representative</p>
        </td>
        <td width="4%">&nbsp;</td>
        <td width="48%" style="border:1px solid #e2e8f0;border-radius:6px;padding:14px;text-align:center;vertical-align:top;">
          <p style="font-size:10px;color:#64748b;margin-bottom:4px;">Pihak Kedua</p>
          <p style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:55px;">{{ $hotel->name }}</p>
          <p style="font-size:11px;font-weight:700;border-top:1px solid #cbd5e1;padding-top:8px;color:#1e293b;">{{ $owner->name }}</p>
          <p style="font-size:10px;color:#64748b;">Pemilik / PIC Properti</p>
        </td>
      </tr>
    </table>
  </div>

  <!-- Doc Footer -->
  <div class="doc-footer">
    ArahInn.com &mdash; Perjanjian Kemitraan Akomodasi &mdash; {{ $date }}
    &mdash; Dokumen ini dibuat secara elektronik dan sah tanpa tanda tangan basah.
  </div>

</div>
</body>
</html>
