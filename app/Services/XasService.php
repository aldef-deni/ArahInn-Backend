<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * XasService — integrasi Rajabiller XAS (SAAS Travel Checkout Page).
 *
 * Pattern: Mode Checkout Page (paling simple).
 * - Mitra POST request credential ke Rajabiller dengan target page (kereta/pesawat/dlu/pelni).
 * - Rajabiller respond dengan embed_fe_url one-time.
 * - Mitra redirect customer ke embed_fe_url (iframe atau full redirect).
 * - Customer book + bayar fully di webview Rajabiller/Winpay.
 * - Selesai → redirect ke url_redirect (back ke ArahInn).
 *
 * Endpoint:
 *   POST <base>/travel/auth/credential   (untuk Travel: kereta/pesawat/dlu/pelni)
 *   POST <base>/ppob/auth/credential     (untuk PPOB, kalau migrasi pattern XAS)
 *
 * Response codes (sama dengan PPOB):
 *   00 = sukses, 01 = pin salah, 06 = saldo kurang, 16 = gagal, 68 = pending, dll
 */
class XasService
{
    /** Allowed page values per Rajabiller docs */
    public const PAGES = ['kereta', 'pesawat', 'dlu', 'pelni'];

    private string $baseUrl;
    private string $clientKey;
    private string $clientSecret;
    private string $idOutlet;
    private string $pin;
    private string $merchant;
    private int    $timeout;
    private string $callbackUrl;
    private string $redirectUrl;

    public function __construct()
    {
        $cfg = config('services.raja_xas');
        $this->baseUrl      = rtrim($cfg['url'] ?? '', '/');
        $this->clientKey    = (string) ($cfg['client_key']    ?? '');
        $this->clientSecret = (string) ($cfg['client_secret'] ?? '');
        $this->idOutlet     = (string) ($cfg['id_outlet']     ?? '');
        $this->pin          = (string) ($cfg['pin']           ?? '');
        $this->merchant     = (string) ($cfg['merchant']      ?? 'arahinn');
        $this->timeout      = (int)    ($cfg['timeout']       ?? 30);
        $this->callbackUrl  = (string) ($cfg['url_callback']  ?? '');
        $this->redirectUrl  = (string) ($cfg['url_redirect']  ?? '');
    }

    /**
     * Generate credential untuk Travel webview (Checkout Page mode).
     *
     * @param string      $page       kereta | pesawat | dlu | pelni
     * @param string      $userPhone  Nomor HP user (untuk pre-fill di Rajabiller)
     * @param string|null $tokenMitra Optional override (default auto-generate UUID-ish)
     * @return array { rc, rd, embed_fe_url, expired_time, token_mitra, _http_status }
     */
    public function generateTravelCredential(string $page, string $userPhone, ?string $tokenMitra = null): array
    {
        if (!in_array($page, self::PAGES, true)) {
            throw new \InvalidArgumentException("Invalid XAS page: {$page}. Valid: " . implode(', ', self::PAGES));
        }

        return $this->requestCredential('/travel/auth/credential', $page, $userPhone, $tokenMitra);
    }

    /**
     * Generate credential untuk PPOB webview (Checkout Page mode).
     * Catatan: PPOB normalnya pakai integrasi API JSON langsung (RajaBillerService),
     * tapi opsi XAS PPOB tersedia untuk skenario customer-facing checkout terpadu.
     */
    public function generatePpobCredential(string $userPhone, ?string $tokenMitra = null): array
    {
        return $this->requestCredential('/ppob/auth/credential', null, $userPhone, $tokenMitra);
    }

    /**
     * Core HTTP request ke endpoint credential.
     */
    private function requestCredential(string $path, ?string $page, string $userPhone, ?string $tokenMitra): array
    {
        $tokenMitra ??= $this->generateTokenMitra();

        $payload = [
            'client_key'   => $this->clientKey,
            'id_outlet'    => $this->idOutlet,
            'pin'          => $this->pin,
            'merchant'     => $this->merchant,
            'hp'           => $userPhone,
            'url_callback' => $this->callbackUrl,
            'url_redirect' => $this->redirectUrl,
            'token_mitra'  => $tokenMitra,
        ];
        if ($page) {
            $payload['page'] = $page;
        }

        // Validasi env credential sudah di-set
        if (empty($this->clientKey) || empty($this->clientSecret)) {
            Log::warning('XasService: client_key/client_secret belum di-set di .env', [
                'has_client_key'    => (bool) $this->clientKey,
                'has_client_secret' => (bool) $this->clientSecret,
            ]);
            return [
                'rc'           => 'CONFIG',
                'rd'           => 'XAS belum di-konfigurasi. Hubungi admin untuk setup credential.',
                'embed_fe_url' => null,
                'expired_time' => null,
                'token_mitra'  => $tokenMitra,
                '_http_status' => 0,
            ];
        }

        $start = microtime(true);
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Content-Type'  => 'application/json',
                    'client-secret' => $this->clientSecret,
                ])
                ->post($this->baseUrl . $path, $payload);

            $duration = (int) round((microtime(true) - $start) * 1000);
            $data     = $response->json() ?: [];

            Log::info('XAS credential request', [
                'path'        => $path,
                'page'        => $page,
                'token_mitra' => $tokenMitra,
                'http_status' => $response->status(),
                'rc'          => $data['rc'] ?? null,
                'duration_ms' => $duration,
            ]);

            return [
                'rc'           => $data['rc'] ?? null,
                'rd'           => $data['rd'] ?? null,
                'embed_fe_url' => $data['embed_fe_url'] ?? null,
                'expired_time' => $data['expired_time'] ?? null,
                'token_mitra'  => $tokenMitra,
                '_http_status' => $response->status(),
                '_duration_ms' => $duration,
            ];
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $start) * 1000);
            Log::error('XAS credential request failed', [
                'path'    => $path,
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            return [
                'rc'           => 'ERR',
                'rd'           => 'Koneksi ke vendor gagal. Coba lagi nanti.',
                'embed_fe_url' => null,
                'expired_time' => null,
                'token_mitra'  => $tokenMitra,
                '_http_status' => 0,
                '_duration_ms' => $duration,
            ];
        }
    }

    /**
     * Generate token_mitra unique per request (anti-replay).
     */
    private function generateTokenMitra(): string
    {
        return 'mtr_' . now()->format('YmdHis') . '_' . strtolower(Str::random(16));
    }

    /**
     * Mapping RC ke user-friendly message (sama pattern dengan RajaBillerService).
     */
    public static function userMessage(?string $rc): string
    {
        return match ($rc) {
            '00'    => 'Sukses',
            '01'    => 'Kredensial salah. Hubungi admin.',
            '03'    => 'ID pelanggan tidak terdaftar.',
            '04'    => 'Masih ada tunggakan sebelumnya.',
            '05'    => 'Format data salah.',
            '06'    => 'Saldo vendor tidak cukup. Hubungi admin.',
            '08'    => 'Tagihan sudah terbayar.',
            '09'    => 'Terlalu banyak request, tunggu beberapa menit.',
            '16'    => 'Transaksi gagal. Coba lagi atau hubungi admin.',
            '68'    => 'Transaksi sedang diproses, mohon tunggu.',
            '77'    => 'Vendor sedang gangguan, coba lagi nanti.',
            '87'    => 'ID pelanggan diblokir.',
            '97'    => 'Duplikat transaksi terdeteksi. Tunggu 5-15 menit.',
            'CONFIG'=> 'Layanan XAS belum tersedia.',
            'ERR'   => 'Koneksi ke vendor gagal.',
            default => 'Terjadi kesalahan, coba lagi nanti.',
        };
    }

    public static function isSuccess(?string $rc): bool { return $rc === '00'; }
    public static function isPending(?string $rc): bool { return $rc === '68'; }
    public static function isFailed(?string $rc): bool {
        return $rc !== null && !in_array($rc, ['00', '68'], true);
    }
}
