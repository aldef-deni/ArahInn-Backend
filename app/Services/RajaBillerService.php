<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RajaBillerService — integrasi PPOB aggregator Rajabiller.
 *
 * Auth: UID + PIN dikirim di body JSON (no signature).
 * Endpoint tunggal POST: {baseUrl} dengan body { method, uid, pin, ... }.
 *
 * 4 methods supported:
 *   - info       : Get product catalog (per group atau per produk)
 *   - cek        : Inquiry tagihan (POSTPAID + PLN Prabayar)
 *   - bayar      : Payment eksekusi (PREPAID + POSTPAID step 2)
 *   - cek_status : Check status transaksi pending (TBD method name)
 *
 * Catatan response inconsistency yang sudah dikonfirmasi via test:
 *   - Saldo akhir: `sisa_saldo` (Pulsa) vs `saldo_akhir` (PLN, PDAM)
 *   - SN voucher : `sn` (Pulsa) vs `token` (PLN)
 *   - Harga beli : `saldo_terpotong` (Pulsa) vs `harga` (PLN, PDAM)
 * Service ini auto-normalize via extractCommonFields().
 */
class RajaBillerService
{
    private string $baseUrl;
    private string $uid;
    private string $pin;
    private int    $timeout;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.raja_biller.url', 'https://c-dev-api.rajabiller.com/api_json.php');
        $this->uid     = (string) config('services.raja_biller.uid', '');
        $this->pin     = (string) config('services.raja_biller.pin', '');
        $this->timeout = (int)    config('services.raja_biller.timeout', 45); // doctek says 45s
    }

    /* ──────────────────────────────────────────────────────────────────
     | Method: info
     | Get product catalog. Pass group name (e.g. "TELKOMSEL") atau
     | specific product code (e.g. "S10H").
     ────────────────────────────────────────────────────────────────── */
    public function info(string $produkOrGroup): array
    {
        return $this->call([
            'method' => 'info',
            'uid'    => $this->uid,
            'pin'    => $this->pin,
            'produk' => $produkOrGroup,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     | Method: cek (Inquiry)
     | Cek tagihan POSTPAID atau cek customer PLN Prabayar.
     |
     | Field $extra optional per produk type:
     |   PLN Prabayar : ['nominal' => '20000']
     |   BPJS Kes     : ['periode' => '1'] (jumlah bulan)
     |   PBB          : ['tahun'   => '2026']
     |   Samsat Jatim : ['noktp'   => '...', 'nomesin' => '...', 'notelp' => '...', 'email' => '...']
     |   Game Online  : ['server'  => '...']
     ────────────────────────────────────────────────────────────────── */
    public function cek(string $produk, string $idpel, string $ref1, array $extra = []): array
    {
        return $this->call(array_merge([
            'method' => 'cek',
            'uid'    => $this->uid,
            'pin'    => $this->pin,
            'produk' => $produk,
            'idpel'  => $idpel,
            'ref1'   => $ref1,
        ], $extra));
    }

    /* ──────────────────────────────────────────────────────────────────
     | Method: bayar (Payment)
     | Eksekusi pembayaran. Untuk POSTPAID pakai ref1 sama dengan cek().
     | Untuk PREPAID pakai ref1 baru.
     ────────────────────────────────────────────────────────────────── */
    public function bayar(string $produk, string $idpel, string $ref1, array $extra = []): array
    {
        return $this->call(array_merge([
            'method' => 'bayar',
            'uid'    => $this->uid,
            'pin'    => $this->pin,
            'produk' => $produk,
            'idpel'  => $idpel,
            'ref1'   => $ref1,
        ], $extra));
    }

    /* ──────────────────────────────────────────────────────────────────
     | Method: cek_status / status (TBD)
     | Cek status final transaksi yang masih pending.
     | NOTE: Method name belum konfirmasi 100% — TBD setelah callback test.
     ────────────────────────────────────────────────────────────────── */
    public function cekStatus(string $ref1): array
    {
        return $this->call([
            'method' => 'cek_status',
            'uid'    => $this->uid,
            'pin'    => $this->pin,
            'ref1'   => $ref1,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     | Generate unique ref1 untuk request.
     | Format: ARH-{timestamp}-{random}
     | Max 100 char per doktek.
     ────────────────────────────────────────────────────────────────── */
    public function generateRef1(string $prefix = 'ARH'): string
    {
        return $prefix . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(8));
    }

    /* ──────────────────────────────────────────────────────────────────
     | Normalize response fields agar konsisten antar produk.
     | Map field-field yang BEDA NAMA antar produk ke standard:
     ────────────────────────────────────────────────────────────────── */
    public function extractCommonFields(array $resp): array
    {
        return [
            'rc'              => $resp['rc']              ?? null,
            'status_text'     => $resp['status']          ?? null,
            'trxid'           => $resp['trxid']           ?? null,
            'mid'             => $resp['mid']             ?? null,           // Rajabiller merchant id
            'refid'           => $resp['refid']           ?? null,           // Rajabiller reference
            'idpel'           => $resp['idpel']           ?? null,
            'produk_label'    => $resp['produk']          ?? null,           // "Beli S10H" / "BAYAR PLNPRA"
            'waktu_trx'       => $resp['waktu_trx']       ?? null,

            // Voucher/Token — normalize ke 1 field 'sn'
            'sn'              => $resp['token']           ?? $resp['sn'] ?? null,

            // Customer info (PLN, PDAM, BPJS)
            'nama'            => $resp['nama']            ?? null,
            'alamat'          => $resp['alamat']          ?? null,
            'provider'        => $resp['provider']        ?? null,
            'tarif_daya'      => $resp['tarif_daya']      ?? null,

            // Pricing
            'tagihan'         => $this->toFloat($resp['tagihan']     ?? null),
            'adm'             => $this->toFloat($resp['adm']         ?? null),
            'total_bayar'     => $this->toFloat($resp['total_bayar'] ?? null),

            // Harga beli (saldo terpotong) — normalize
            'cost_price'      => $this->toFloat($resp['harga'] ?? $resp['saldo_terpotong'] ?? null),

            // Saldo deposit Rajabiller setelah trx — normalize
            'saldo_akhir'     => $this->toFloat($resp['saldo_akhir'] ?? $resp['sisa_saldo'] ?? null),

            // PLN specific
            'jumlah_kwh'      => $resp['jumlah_kwh']      ?? null,
            'rp_token'        => $this->toFloat($resp['rp_token'] ?? null),
            'ppj'             => $this->toFloat($resp['ppj']      ?? null),

            // PDAM/postpaid specific
            'jml_bulan'       => $resp['jml_bulan']       ?? null,
            'blth'            => $resp['blth']            ?? null,           // "JUL 25"
            'denda'           => $this->toFloat($resp['denda'] ?? null),

            // Receipt/struk
            'struk_url'       => $resp['struk']           ?? null,
            'template_struk'  => $resp['template_struk']  ?? null,
            'info'            => $resp['info']            ?? null,

            // Raw response for storage
            'raw'             => $resp,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     | HTTP wrapper dengan timeout + error handling per doctek.
     |
     | HTTP code mapping (per doctek halaman 1 + RC API TERBARU):
     |   200 OK            → normal response (cek body rc)
     |   401               → user/pin salah → treat as FAILED
     |   403               → produk belum di-open → FAILED
     |   429               → rate limit 10x → FAILED, retry 5 min
     |   lain (5xx, dll)   → unknown → treat as PENDING (tunggu callback)
     ────────────────────────────────────────────────────────────────── */
    private function call(array $payload): array
    {
        if (empty($this->uid) || empty($this->pin)) {
            return [
                'rc'           => '01',
                'status'       => 'UID/PIN belum dikonfigurasi',
                '_treat_as'    => 'failed',
                '_http_status' => 0,
            ];
        }

        try {
            $start = microtime(true);

            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl, $payload);

            $duration = round((microtime(true) - $start) * 1000); // ms
            $body     = $response->json() ?? [];
            $status   = $response->status();

            // Determine treatment
            $treatAs = match (true) {
                $status === 200                 => 'normal',
                in_array($status, [401, 403, 429], true) => 'failed',
                default                         => 'pending', // timeout/5xx → pending
            };

            $body['_treat_as']    = $treatAs;
            $body['_http_status'] = $status;
            $body['_duration_ms'] = $duration;

            // Log every call (info level for monitoring)
            Log::info('RajaBiller API call', [
                'method'      => $payload['method']  ?? '?',
                'produk'      => $payload['produk']  ?? '?',
                'ref1'        => $payload['ref1']    ?? null,
                'http_status' => $status,
                'rc'          => $body['rc']         ?? null,
                'duration_ms' => $duration,
            ]);

            return $body;

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Timeout/network — treat as pending per doctek
            Log::warning('RajaBiller connection timeout', [
                'method' => $payload['method'] ?? '?',
                'ref1'   => $payload['ref1']   ?? null,
                'error'  => $e->getMessage(),
            ]);

            return [
                'rc'           => null,
                'status'       => 'Connection timeout',
                '_treat_as'    => 'pending',
                '_http_status' => 0,
                '_error'       => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('RajaBiller call exception', [
                'method' => $payload['method'] ?? '?',
                'ref1'   => $payload['ref1']   ?? null,
                'error'  => $e->getMessage(),
            ]);

            return [
                'rc'           => null,
                'status'       => 'Exception',
                '_treat_as'    => 'pending',
                '_http_status' => 0,
                '_error'       => $e->getMessage(),
            ];
        }
    }

    private function toFloat($v): ?float
    {
        if ($v === null || $v === '' || $v === '-') {
            return null;
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /* ──────────────────────────────────────────────────────────────────
     | Static helpers untuk Response Code interpretation per doctek
     ────────────────────────────────────────────────────────────────── */

    public const RC_SUCCESS = '00';
    public const RC_PENDING = '68';

    public static function isSuccess(?string $rc): bool
    {
        return $rc === self::RC_SUCCESS;
    }

    public static function isPending(?string $rc): bool
    {
        return $rc === self::RC_PENDING;
    }

    public static function isFailed(?string $rc): bool
    {
        if ($rc === null) return false; // treat null as pending
        return !self::isSuccess($rc) && !self::isPending($rc);
    }

    /**
     * Deteksi pesan vendor yang MENGINDIKASIKAN transaksi masih diproses
     * (walau RC bukan 68). Dipakai untuk mencegah refund prematur → double-payout.
     * Sengaja pakai frasa SPESIFIK supaya tidak salah-tangkap pesan gagal seperti
     * "tidak dapat diproses".
     */
    public static function isProcessingMessage(?string $message): bool
    {
        if (!$message) return false;
        $m = strtoupper($message);
        foreach (['SEDANG DIPROSES', 'SEDANG PROSES', 'DALAM PROSES', 'MASIH DIPROSES',
                  'MASIH PROSES', 'PENDING', 'MENUNGGU', 'IN PROCESS', 'PROCESSING'] as $needle) {
            if (str_contains($m, $needle)) return true;
        }
        return false;
    }

    /**
     * Map RC ke user-friendly message untuk display di UI.
     */
    public static function userMessage(?string $rc): string
    {
        return match ($rc) {
            '00' => 'Transaksi berhasil',
            '03' => 'Nomor pelanggan tidak ditemukan',
            '04' => 'Anda memiliki tunggakan yang belum dibayar',
            '05' => 'Format nomor tidak sesuai',
            '06' => 'Layanan sementara tidak tersedia, coba beberapa saat lagi',
            '08' => 'Tagihan ini sudah dibayar',
            '09' => 'Terlalu banyak permintaan, coba lagi dalam beberapa menit',
            '16' => 'Transaksi gagal, mohon coba lagi',
            '68' => 'Transaksi sedang diproses, mohon tunggu',
            '77' => 'Layanan provider sedang bermasalah, coba lagi nanti',
            '87' => 'Nomor pelanggan diblokir oleh provider',
            '97' => 'Transaksi duplikat terdeteksi',
            null => 'Transaksi sedang diproses',
            // 01 tidak di-expose ke user (auth issue = config error internal)
            default => 'Transaksi gagal',
        };
    }

    /**
     * RC yang perlu alert admin (config issue / saldo habis / biller down).
     */
    public static function shouldAlertAdmin(?string $rc): bool
    {
        return in_array($rc, ['01', '06', '77'], true);
    }
}
