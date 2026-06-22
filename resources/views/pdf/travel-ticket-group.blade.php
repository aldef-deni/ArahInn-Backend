@php
  // Style pakai accent leg pertama (PP semua pesawat → accent sama).
  $accent = $legs[0]['accent'] ?? '#0284c7';
  $accentSoft = $legs[0]['accentSoft'] ?? '#e0f2fe';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>E-Tiket Pulang-Pergi</title>
<style>
  @page { margin: 20mm 16mm; size: A4 portrait; }
  body { font-family: DejaVu Sans, sans-serif; color: #1a202c; font-size: 11px; line-height: 1.5; margin: 0; }
  .brand { color: #0e7490; font-weight: bold; }
  .muted { color: #64748b; }
  .small { font-size: 10px; }

  .leg-tag { display: inline-block; background: {{ $accent }}; color: #fff; padding: 4px 14px; border-radius: 999px; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }

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

  .total-box { border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 16px; margin-top: 8px; }
  .total-box table { width: 100%; }
  .total-box .lbl { font-size: 11px; color: #475569; }
  .total-box .amt { font-size: 18px; font-weight: bold; color: {{ $accent }}; text-align: right; }

  .footer { margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 9px; color: #64748b; line-height: 1.6; }

  .watermark { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; }
  .watermark img { width: 100%; height: 100%; }
</style>
</head>
<body>
  @php
    $logoPaths = [public_path('logo-arahin.png'), public_path('logo-arahinn.png'), public_path('logo.png')];
    $logoBase64 = null;
    foreach ($logoPaths as $p) { if (is_file($p)) { $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($p)); break; } }
    $bgPath = public_path('etiket-bg.jpg');
    $bgBase64 = is_file($bgPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($bgPath)) : null;
  @endphp

  @if ($bgBase64)
    <div class="watermark"><img src="{{ $bgBase64 }}" alt=""></div>
  @endif

  @foreach ($legs as $leg)
    <div @unless($loop->last) style="page-break-after: always;" @endunless>
      <div class="leg-tag">{{ $loop->first ? 'Penerbangan Pergi' : 'Penerbangan Pulang' }}</div>
      @include('pdf._ticket-body', $leg + ['logoBase64' => $logoBase64])
    </div>
  @endforeach
</body>
</html>
