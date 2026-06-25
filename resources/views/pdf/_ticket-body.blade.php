{{-- Isi 1 e-tiket (header + kode + rute + detail + penumpang + total + footer).
     Dipakai oleh travel-ticket (1 leg) & travel-ticket-group (PP, di-loop per leg).
     Butuh: $logoBase64, $b, $modaLabel, $serviceLabel, $statusLabel, $issuedAt,
            $departTime, $arriveTime, $originName, $destinationName, $departDate, $pax, $totalPrice --}}
<div class="header">
  <table>
    <tr>
      <td style="vertical-align: top;">
        @if (!empty($logoBase64))
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
      <td><div class="k">{{ $serviceLabel }}</div><div class="v">{{ !empty($airlineName) ? $airlineName . ' · ' . ($b->service_name ?: '') : ($b->service_name ?: '—') }}</div></td>
      <td><div class="k">Kelas</div><div class="v">{{ $b->class ?: '—' }}</div></td>
    </tr>
    <tr>
      <td><div class="k">Tanggal Berangkat</div><div class="v">{{ $departDate }}</div></td>
      <td><div class="k">Jumlah Penumpang</div><div class="v">{{ $b->pax }} orang</div></td>
    </tr>
    @if($b->moda === 'pelni')
    <tr>
      <td><div class="k">Total Harga</div><div class="v" style="color:{{ $accent }}; font-weight:bold;">Rp {{ $totalPrice }}</div></td>
      <td></td>
    </tr>
    @endif
  </table>
</div>

@if(!empty($baggage))
<div class="section">
  <div class="section-title">Ketentuan Tiket</div>
  <table class="info-grid">
    <tr>
      <td><div class="k">Ketentuan Bagasi</div><div class="v">{{ $baggage }}</div></td>
      <td><div class="k">Pengembalian &amp; Perubahan</div><div class="v" style="color:#dc2626;">Non Refund &nbsp;·&nbsp; Non Reschedule</div></td>
    </tr>
  </table>
</div>
@endif

@if(count($pax))
<div class="section">
  <div class="section-title">Data Penumpang</div>
  <table class="data">
    <thead>
      <tr>
        <th style="width:6%;">No</th>
        <th>Nama</th>
        <th style="width:14%;">Tipe</th>
        <th style="width:20%;">Warga Negara</th>
        <th style="width:30%;">Identitas</th>
      </tr>
    </thead>
    <tbody>
      @foreach($pax as $i => $p)
      <tr>
        <td style="vertical-align:top;">{{ $i + 1 }}</td>
        <td style="vertical-align:top;">
          {{ $p['name'] }}
          @if(!empty($p['birthdate']))<div style="font-size:9px; color:#94a3b8; margin-top:2px;">Lahir: {{ $p['birthdate'] }}</div>@endif
        </td>
        <td style="vertical-align:top;">{{ $p['type'] }}</td>
        <td style="vertical-align:top;">{{ $p['nationality'] ?: '—' }}</td>
        <td style="vertical-align:top;">
          <strong>{{ $p['idLabel'] ?? 'NIK' }}:</strong> {{ $p['id'] ?: '—' }}
          @if(empty($p['isForeign']) && !empty($p['hasPassport']) && !empty($p['passport']))
            <div style="margin-top:2px;"><strong>Paspor:</strong> {{ $p['passport'] }}</div>
          @endif
          @if(!empty($p['hasPassport']) && (!empty($p['passportCountry']) || !empty($p['passportIssue']) || !empty($p['passportExpiry'])))
            <div style="font-size:9px; color:#94a3b8; margin-top:3px; line-height:1.5;">
              @if(!empty($p['passportCountry']))Penerbit: {{ $p['passportCountry'] }}<br>@endif
              @if(!empty($p['passportIssue']))Terbit: {{ $p['passportIssue'] }} @endif
              @if(!empty($p['passportExpiry']))Berlaku s/d: {{ $p['passportExpiry'] }}@endif
            </div>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

@if(!empty($flightNotes))
<div class="section">
  <div class="section-title">Catatan Penting</div>
  <ol style="font-size:9px; color:#475569; line-height:1.6; padding-left:16px; margin:4px 0 0;">
    @foreach($flightNotes as $n)
      <li>{{ $n }}</li>
    @endforeach
  </ol>
</div>
@endif
@if(!empty($flightNotesEn))
<div class="section">
  <div class="section-title">Important Notes</div>
  <ol style="font-size:9px; color:#475569; line-height:1.6; padding-left:16px; margin:4px 0 0;">
    @foreach($flightNotesEn as $n)
      <li>{{ $n }}</li>
    @endforeach
  </ol>
</div>
@endif

<div class="footer">
  <strong>Penting:</strong> Tunjukkan e-tiket ini (cetak atau di layar) beserta identitas asli saat check-in.
  Tiba di stasiun/bandara/pelabuhan minimal 60–90 menit sebelum keberangkatan.
  E-tiket ini diterbitkan resmi oleh ArahInn bekerja sama dengan operator terkait.<br>
  Butuh bantuan? Hubungi cs@arahinn.com · Dokumen dibuat otomatis, tanpa tanda tangan basah.
</div>
