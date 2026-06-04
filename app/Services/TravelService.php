<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TravelService — integrasi Rajabiller Travel API LANGSUNG (bukan XAS webview).
 *
 * Auth: JWT token via POST /app/sign_in {outletId, pin}. Token valid 1 hari,
 * dikirim di BODY tiap request. TIDAK ada signature/hash.
 *
 * Fase 1: KERETA (productCode "WKAI").
 * Flow: station → search → book → [get-seat-layout → change_seat] → payment.
 *
 * Doc: docs/rajabiller-travel-kai-api.md
 *
 * Response codes (pola sama PPOB): 00=sukses, 33=tidak ditemukan, dll.
 */
class TravelService
{
    public const PRODUCT_KERETA = 'WKAI';

    /** Channel: KAI di devel, pesawat/pelni di production. */
    public const CH_KAI  = 'kai';   // kereta — DEVEL
    public const CH_PROD = 'prod';  // pesawat & pelni — PRODUCTION

    private const TOKEN_TTL_SEC = 23 * 3600; // token valid 1 hari, refresh 23 jam

    private int $timeout;
    /** @var array<string,array{url:string,outletId:string,pin:string}> */
    private array $env;

    public function __construct()
    {
        $cfg = config('services.raja_travel');
        $this->timeout = (int) ($cfg['timeout'] ?? 45);
        $this->env = [
            self::CH_KAI => [
                'url'      => rtrim($cfg['kai_url'] ?? '', '/'),
                'outletId' => (string) ($cfg['kai_outlet_id'] ?? ''),
                'pin'      => (string) ($cfg['kai_pin'] ?? ''),
            ],
            self::CH_PROD => [
                'url'      => rtrim($cfg['prod_url'] ?? '', '/'),
                'outletId' => (string) ($cfg['prod_outlet_id'] ?? ''),
                'pin'      => (string) ($cfg['prod_pin'] ?? ''),
            ],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * AUTH
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Ambil JWT token untuk channel (cache harian per channel).
     */
    public function getToken(string $channel, bool $forceRefresh = false): ?string
    {
        $key = "raja_travel:token:{$channel}";
        if (!$forceRefresh) {
            $cached = Cache::get($key);
            if ($cached) return $cached;
        }

        $res = $this->signIn($channel);
        $token = $res['token'] ?? null;
        if ($token) {
            Cache::put($key, $token, self::TOKEN_TTL_SEC);
        }
        return $token;
    }

    /**
     * POST /app/sign_in — dapat token + balance untuk channel tertentu.
     * @return array { rc, rd, token, balance, _http_status }
     */
    public function signIn(string $channel): array
    {
        $e = $this->env[$channel];
        $res = $this->raw($channel, '/app/sign_in', [
            'outletId' => $e['outletId'],
            'pin'      => $e['pin'],
        ], withToken: false);

        return [
            'rc'           => $res['rc']   ?? null,
            'rd'           => $res['rd']   ?? null,
            'token'        => $res['token'] ?? null,
            'balance'      => $res['data']['balance'] ?? null,
            '_http_status' => $res['_http_status'] ?? 0,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * KERETA — FLOW
     * ────────────────────────────────────────────────────────────────── */

    /** POST /train/station — daftar stasiun. */
    public function stations(): array
    {
        return $this->authed(self::CH_KAI, '/train/station', []);
    }

    /**
     * POST /train/search — cari jadwal kereta.
     * @param array $p { origin, destination, date(YYYY-MM-DD), adult, infant }
     */
    public function searchTrain(array $p): array
    {
        return $this->authed(self::CH_KAI, '/train/search', [
            'productCode' => self::PRODUCT_KERETA,
            'origin'      => $p['origin'],
            'destination' => $p['destination'],
            'date'        => $p['date'],
            'adult'       => (string) ($p['adult']  ?? 1),
            'infant'      => (string) ($p['infant'] ?? 0),
        ]);
    }

    /**
     * POST /train/book — booking kereta (belum bayar).
     * @param array $p semua field jadwal + passengers{adults[],infants[]}
     *   Wajib: origin, destination, date, trainNumber, grade, class,
     *          adult, child, infant, priceAdult, priceChild, priceInfant,
     *          trainName, departureStation, departureTime, arrivalStation,
     *          arrivalTime, passengers
     * @return array + data{ bookingCode, transactionId, seats[], nominalAdmin,
     *                        normalSales, extraFee, discount, timeLimit }
     */
    public function bookTrain(array $p): array
    {
        $payload = array_merge([
            'productCode' => self::PRODUCT_KERETA,
        ], $p);

        return $this->authed(self::CH_KAI, '/train/book', $payload);
    }

    /**
     * POST /train/get-seat-layout — denah kursi gerbong.
     * @param array $p { origin, destination, date, trainNumber }
     */
    public function seatLayout(array $p): array
    {
        return $this->authed(self::CH_KAI, '/train/get-seat-layout', [
            'productCode' => self::PRODUCT_KERETA,
            'origin'      => $p['origin'],
            'destination' => $p['destination'],
            'date'        => $p['date'],
            'trainNumber' => $p['trainNumber'],
        ]);
    }

    /**
     * POST /train/change_seat — ganti kursi (versi recommended dgn wagon per-seat).
     * @param string $bookingCode
     * @param string $transactionId
     * @param array  $seats  [{ wagonCode, wagonNumber, row, column }, ...]
     */
    public function changeSeat(string $bookingCode, string $transactionId, array $seats): array
    {
        return $this->authed(self::CH_KAI, '/train/change_seat', [
            'productCode'   => self::PRODUCT_KERETA,
            'bookingCode'   => $bookingCode,
            'transactionId' => $transactionId,
            'seats'         => $seats,
        ]);
    }

    /** POST /train/cancel_book — batalkan booking yang belum dibayar. */
    public function cancelBook(string $bookingCode, string $transactionId, string $reason): array
    {
        return $this->authed(self::CH_KAI, '/train/cancel_book', [
            'productCode'   => self::PRODUCT_KERETA,
            'bookingCode'   => $bookingCode,
            'transactionId' => $transactionId,
            'reason'        => $reason,
        ]);
    }

    /**
     * POST /train/payment — bayar (potong saldo deposit Rajabiller) + issue tiket.
     * @return array + data{ transaction_id, url_etiket, url_image, url_struk, komisi }
     */
    public function payTrain(string $bookingCode, string $transactionId, array $money): array
    {
        return $this->authed(self::CH_KAI, '/train/payment', [
            'productCode'   => self::PRODUCT_KERETA,
            'bookingCode'   => $bookingCode,
            'transactionId' => $transactionId,
            'nominal'       => $money['nominal'],
            'nominal_admin' => $money['nominal_admin'] ?? 0,
            'discount'      => $money['discount'] ?? 0,
            'pay_type'      => 'TUNAI',
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * GENERAL — cek transaksi
     * ────────────────────────────────────────────────────────────────── */

    /** Channel transaksi berdasar produk: KERETA→devel, pesawat/pelni→prod. */
    private function channelForProduct(string $product): string
    {
        return strtoupper($product) === 'KERETA' ? self::CH_KAI : self::CH_PROD;
    }

    public function transactionInfo(string $transactionId, string $product = 'KERETA'): array
    {
        return $this->authed($this->channelForProduct($product), '/app/transaction_info', [
            'product'        => $product,
            'transaction_id' => $transactionId,
        ]);
    }

    public function transactionStatus(string $bookCode, string $product = 'KERETA'): array
    {
        return $this->authed($this->channelForProduct($product), '/app/transaction_status', [
            'product'  => $product,
            'bookCode' => $bookCode,
        ]);
    }

    public function transactionList(string $product = 'KERETA'): array
    {
        return $this->authed($this->channelForProduct($product), '/app/transaction_list', [
            'product' => $product,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * HTTP CORE
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Panggil endpoint dengan token (inject otomatis) untuk channel tertentu.
     * Retry sekali kalau token expired.
     */
    private function authed(string $channel, string $path, array $payload): array
    {
        $e = $this->env[$channel] ?? null;
        if (!$e || empty($e['outletId']) || empty($e['pin'])) {
            Log::warning("TravelService: credential channel {$channel} belum di-set");
            return ['rc' => 'CONFIG', 'rd' => 'Travel belum dikonfigurasi. Hubungi admin.', '_http_status' => 0];
        }

        $token = $this->getToken($channel);
        $res   = $this->raw($channel, $path, array_merge($payload, ['token' => $token]));

        // Token expired/invalid → refresh sekali lalu retry
        if ($this->isAuthError($res['rc'] ?? null, $res['_http_status'] ?? 0)) {
            $token = $this->getToken($channel, forceRefresh: true);
            if ($token) {
                $res = $this->raw($channel, $path, array_merge($payload, ['token' => $token]));
            }
        }

        return $res;
    }

    /**
     * Raw HTTP POST JSON ke Rajabiller Travel (base URL per channel).
     * @return array decoded json + _http_status (+ _duration_ms)
     */
    private function raw(string $channel, string $path, array $payload, bool $withToken = true): array
    {
        $baseUrl = $this->env[$channel]['url'] ?? '';
        $start = microtime(true);
        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl . $path, $payload);

            $duration = (int) round((microtime(true) - $start) * 1000);
            $data     = $response->json() ?: [];

            Log::info('Travel API request', [
                'channel'     => $channel,
                'path'        => $path,
                'http_status' => $response->status(),
                'rc'          => $data['rc'] ?? null,
                'duration_ms' => $duration,
            ]);

            $data['_http_status'] = $response->status();
            $data['_duration_ms'] = $duration;
            return $data;
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $start) * 1000);
            Log::error('Travel API request failed', [
                'path'    => $path,
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            return [
                'rc'           => 'ERR',
                'rd'           => 'Koneksi ke vendor gagal. Coba lagi nanti.',
                '_http_status' => 0,
                '_duration_ms' => $duration,
            ];
        }
    }

    /** Heuristik: apakah response menandakan token expired/invalid. */
    private function isAuthError(?string $rc, int $httpStatus): bool
    {
        if ($httpStatus === 401 || $httpStatus === 403) return true;
        // RC khusus token (sesuaikan kalau doc kasih kode pasti)
        return in_array($rc, ['96', '99', 'TOKEN', 'TOKEN_EXPIRED'], true);
    }

    /* ──────────────────────────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────────────────────────── */

    public static function isSuccess(?string $rc): bool { return $rc === '00'; }

    public static function userMessage(?string $rc): string
    {
        return match ($rc) {
            '00'     => 'Sukses',
            '33'     => 'Data tidak ditemukan.',
            '01'     => 'Kredensial salah. Hubungi admin.',
            '06'     => 'Saldo deposit tidak cukup. Hubungi admin.',
            '16'     => 'Transaksi gagal. Coba lagi.',
            '68'     => 'Transaksi sedang diproses.',
            'CONFIG' => 'Layanan tiket belum tersedia.',
            'ERR'    => 'Koneksi ke vendor gagal.',
            default  => 'Terjadi kesalahan, coba lagi nanti.',
        };
    }
}
