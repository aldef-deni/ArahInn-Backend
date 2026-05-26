<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DokuService
{
    private string $merchantKey = 'default';
    private string $clientId;
    private string $secretKey;
    private string $baseUrl;
    private string $partnerServiceId;
    private array $partnerServiceIdPerBank = [];
    private ?string $privateKeyPem = null;
    private ?string $privateKeyPath = null;

    // v1.1 uses a single unified endpoint; bank is identified by CHANNEL-ID body field
    private const VA_ENDPOINT = '/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va';

    // DOKU SNAP BI VA v1.1 channel mapping.
    // Format channel berbeda tergantung bank — sudah trial:
    //   BCA     → VIRTUAL_ACCOUNT_BCA          (tanpa "BANK_") ✓
    //   Mandiri → VIRTUAL_ACCOUNT_BANK_MANDIRI (dengan "BANK_") ✓
    //   BRI     → VIRTUAL_ACCOUNT_BRI          (tanpa "BANK_")  — try
    //   BSI     → VIRTUAL_ACCOUNT_BSI          (tanpa "BANK_")  — try
    // Tiap key bisa berupa STRING atau ARRAY of strings (fallback chain).
    // Code akan coba opsi pertama; kalau dapat error "Invalid Field Format channel",
    // otomatis retry dengan opsi berikutnya.
    private const VA_CHANNELS = [
        'bca'     => 'VIRTUAL_ACCOUNT_BCA',
        'mandiri' => 'VIRTUAL_ACCOUNT_BANK_MANDIRI',
        'bri'     => ['VIRTUAL_ACCOUNT_BRI', 'VIRTUAL_ACCOUNT_BANK_BRI', 'BRIVA'],
        'bsi'     => ['VIRTUAL_ACCOUNT_BSI', 'VIRTUAL_ACCOUNT_BANK_BSI', 'BSIVA'],
        'bni'     => ['VIRTUAL_ACCOUNT_BNI', 'VIRTUAL_ACCOUNT_BANK_BNI'],
        'permata' => 'VIRTUAL_ACCOUNT_BANK_PERMATA',
        'cimb'    => 'VIRTUAL_ACCOUNT_BANK_CIMB',
        'danamon' => 'VIRTUAL_ACCOUNT_BANK_DANAMON',
        'btn'     => 'VIRTUAL_ACCOUNT_BANK_BTN',
        'doku'    => 'VIRTUAL_ACCOUNT_DOKU',
    ];

    public function __construct()
    {
        // base_url tetap dari config global (sandbox / production)
        $this->baseUrl = rtrim(config('services.doku.base_url', 'https://api-sandbox.doku.com'), '/');

        // Default merchant pertama yang tersedia
        $this->useMerchant($this->resolveDefaultMerchantKey());
    }

    /**
     * Switch credential ke merchant tertentu.
     * Jika $key tidak ditemukan di pool, fallback ke legacy flat config.
     */
    public function useMerchant(string $key): self
    {
        $pool = config('services.doku.merchants', []);
        $cfg  = $pool[$key] ?? null;

        if (!$cfg) {
            // Fallback ke legacy flat config (single merchant)
            $cfg = [
                'client_id'           => config('services.doku.client_id'),
                'secret_key'          => config('services.doku.secret_key'),
                'private_key'         => config('services.doku.private_key'),
                'private_key_path'    => config('services.doku.private_key_path'),
                'partner_service_id'  => config('services.doku.partner_service_id', ''),
                'partner_service_ids' => config('services.doku.partner_service_ids', []),
            ];
            $key = 'default';
        }

        $this->merchantKey      = $key;
        $this->clientId         = (string) ($cfg['client_id'] ?? '');
        $this->secretKey        = (string) ($cfg['secret_key'] ?? '');
        $this->partnerServiceId = (string) ($cfg['partner_service_id'] ?? '');
        $this->privateKeyPem    = $cfg['private_key']      ?? null;
        $this->privateKeyPath   = $cfg['private_key_path'] ?? null;

        // Per-bank partner_service_id (BIN khusus untuk channel tertentu).
        // Format config:
        //   'partner_service_ids' => ['bca' => '12345678', 'mandiri' => '87654321', ...]
        $this->partnerServiceIdPerBank = is_array($cfg['partner_service_ids'] ?? null)
            ? array_change_key_case($cfg['partner_service_ids'], CASE_LOWER)
            : [];

        return $this;
    }

    private function resolvePartnerServiceId(string $bank): string
    {
        $id = $this->partnerServiceIdPerBank[strtolower($bank)]
            ?? $this->partnerServiceId;

        // SNAP BI requires partnerServiceId = 8 chars, left-padded with spaces
        // (so '19008' becomes '   19008')
        return str_pad((string) $id, 8, ' ', STR_PAD_LEFT);
    }

    public function getMerchantKey(): string
    {
        return $this->merchantKey;
    }

    /**
     * Daftar key merchant yang aktif (enabled !== false) di pool.
     */
    public static function availableMerchantKeys(): array
    {
        $pool = config('services.doku.merchants', []);
        $keys = [];
        foreach ($pool as $key => $cfg) {
            if (!empty($cfg['client_id']) && ($cfg['enabled'] ?? true)) {
                $keys[] = $key;
            }
        }

        // Jika pool kosong tapi legacy config terisi, anggap 'default' tersedia
        if (empty($keys) && config('services.doku.client_id')) {
            $keys[] = 'default';
        }

        return $keys;
    }

    private function resolveDefaultMerchantKey(): string
    {
        $keys = self::availableMerchantKeys();
        return $keys[0] ?? 'default';
    }

    // ── Access Token ──────────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $cacheKey = 'doku_access_token_' . $this->merchantKey . '_' . md5($this->clientId);

        return Cache::remember($cacheKey, 800, function () {
            $timestamp    = $this->timestamp();
            $stringToSign = $this->clientId . '|' . $timestamp;

            $privateKey = $this->loadPrivateKey();
            openssl_sign($stringToSign, $rawSig, $privateKey, OPENSSL_ALGO_SHA256);
            $signature = base64_encode($rawSig);

            $response = Http::withHeaders([
                'X-CLIENT-KEY' => $this->clientId,
                'X-TIMESTAMP'  => $timestamp,
                'X-SIGNATURE'  => $signature,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/authorization/v1/access-token/b2b', [
                'grantType' => 'client_credentials',
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('DOKU token error (' . $this->merchantKey . '): ' . $response->body());
            }

            return $response->json('accessToken') ?? throw new \RuntimeException('DOKU token missing in response.');
        });
    }

    // ── Create Virtual Account ────────────────────────────────────────────────

    public function createVirtualAccount(string $bank, string $bookingCode, int $bookingId, float $amount, array $customer, \DateTimeInterface $expiresAt): array
    {
        $bank     = strtolower($bank);
        $endpoint = self::VA_ENDPOINT;

        // Pre-flight: pastikan bank ada di mapping channel
        if (!isset(self::VA_CHANNELS[$bank])) {
            throw new \RuntimeException("Bank '{$bank}' tidak didukung. Pilihan: " . implode(', ', array_keys(self::VA_CHANNELS)));
        }

        // Channel bisa string atau array (fallback chain) — coba satu per satu
        $channelOptions = (array) self::VA_CHANNELS[$bank];
        $lastException  = null;

        foreach ($channelOptions as $channel) {
            try {
                return $this->createVirtualAccountWithChannel($bank, $channel, $bookingCode, $bookingId, $amount, $customer, $expiresAt);
            } catch (\RuntimeException $e) {
                $lastException = $e;
                // Hanya retry kalau error spesifik tentang channel invalid
                if (!preg_match('/Invalid Field Format.*channel|channel.*tidak didukung|channel.*belum di-enable/i', $e->getMessage())) {
                    throw $e;  // error lain → throw langsung, jangan retry
                }
                logger()->info("DOKU: channel '{$channel}' fail, retry dengan alternatif untuk bank {$bank}");
            }
        }

        throw $lastException ?? new \RuntimeException("Semua channel untuk bank {$bank} gagal.");
    }

    /**
     * Internal: actual VA creation untuk channel tertentu.
     */
    private function createVirtualAccountWithChannel(string $bank, string $channel, string $bookingCode, int $bookingId, float $amount, array $customer, \DateTimeInterface $expiresAt): array
    {
        $endpoint = self::VA_ENDPOINT;

        // Pre-flight: pastikan partner_service_id (BIN) untuk bank ini ada di config.
        // Beda bank butuh BIN beda — kalau BIN kosong, DOKU pasti reject.
        $bankBin = $this->partnerServiceIdPerBank[$bank] ?? null;
        if (empty($bankBin) && empty($this->partnerServiceId)) {
            throw new \RuntimeException(
                "BIN (partner_service_id) untuk {$bank} belum di-set untuk merchant '{$this->merchantKey}'. "
                . "Tambahkan di .env: DOKU_" . strtoupper($this->merchantKey === 'default' ? '' : $this->merchantKey . '_')
                . "PARTNER_SERVICE_ID_" . strtoupper($bank) . "=<bin-dari-portal-doku>"
            );
        }
        if (empty($bankBin)) {
            logger()->warning("DOKU: BIN per-bank kosong untuk {$bank} di merchant {$this->merchantKey}, fallback ke partner_service_id default. Ini biasanya menyebabkan error 'Invalid Field Format'.");
        }

        $accessToken = $this->getAccessToken();
        $timestamp   = $this->timestamp();
        $externalId  = Str::uuid()->toString();

        // Pilih partner_service_id sesuai bank (BIN-nya beda per bank)
        $partnerServiceId = $this->resolvePartnerServiceId($bank);

        // customerNo = Prefix Customer(1) + booking ID zero-padded to 7 digits → VA = partnerServiceId(8) + customerNo(8) = 16 chars
        $customerNo = '9' . str_pad((string) ($bookingId % 10000000), 7, '0', STR_PAD_LEFT);
        $vaNumber   = $partnerServiceId . $customerNo;

        $body = [
            'partnerServiceId'    => $partnerServiceId,
            'customerNo'          => $customerNo,
            'virtualAccountNo'    => $vaNumber,
            'virtualAccountName'  => mb_substr($customer['name'] ?? 'Customer', 0, 30),
            'virtualAccountEmail' => $customer['email'] ?? '',
            'virtualAccountPhone' => $customer['phone'] ?? '',
            'trxId'               => $bookingCode,
            'virtualAccountTrxType' => 'C',
            'totalAmount'         => [
                'value'    => number_format($amount, 2, '.', ''),
                'currency' => 'IDR',
            ],
            'expiredDate'         => $expiresAt->format('Y-m-d\TH:i:sP'),
            'additionalInfo'      => [
                'channel'              => $channel,
                'virtualAccountConfig' => ['reusableStatus' => false],
                'notificationUrl'      => rtrim(config('app.url'), '/') . '/api/payments/webhook/doku',
            ],
        ];

        $signature = $this->serviceSignature('POST', $endpoint, $accessToken, $body, $timestamp);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'X-CLIENT-KEY'  => $this->clientId,
            'X-TIMESTAMP'   => $timestamp,
            'X-SIGNATURE'   => $signature,
            'X-PARTNER-ID'  => $this->clientId,
            'X-EXTERNAL-ID' => $externalId,
            'CHANNEL-ID'    => 'H2H',
            'Content-Type'  => 'application/json',
        ])->post($this->baseUrl . $endpoint, $body);

        if ($response->failed()) {
            Cache::forget('doku_access_token_' . $this->merchantKey . '_' . md5($this->clientId));
            $respBody = $response->json();
            $dokuMsg  = $respBody['error']['message']
                ?? $respBody['message']
                ?? $respBody['responseMessage']
                ?? $respBody['responseDescription']
                ?? null;
            $respCode = $respBody['responseCode'] ?? $response->status();

            // Log detail untuk debugging — bisa dilihat di storage/logs/laravel.log
            \Log::warning('DOKU VA create failed', [
                'merchant'  => $this->merchantKey,
                'bank'      => $bank,
                'channel'   => $channel,
                'bin'       => $partnerServiceId,
                'http'      => $response->status(),
                'doku_code' => $respCode,
                'response'  => $respBody,
            ]);

            if ($dokuMsg && str_contains($dokuMsg, 'No static resource')) {
                throw new \RuntimeException('Layanan Virtual Account ' . strtoupper($bank) . ' belum aktif di merchant DOKU (' . $this->merchantKey . '). Aktifkan dulu di portal DOKU → Configuration → Channel.');
            }
            if ($dokuMsg && str_contains($dokuMsg, 'Invalid Field Format')) {
                if (str_contains($dokuMsg, 'partnerServiceId') || str_contains($dokuMsg, 'BIN')) {
                    throw new \RuntimeException('BIN partner_service_id untuk ' . strtoupper($bank) . ' tidak valid (dikirim: "' . trim($partnerServiceId) . '"). Cek .env DOKU_*_PARTNER_SERVICE_ID_' . strtoupper($bank));
                }
                if (str_contains($dokuMsg, 'channel')) {
                    throw new \RuntimeException('Channel "' . $channel . '" belum di-enable di merchant DOKU (' . $this->merchantKey . ') untuk bank ' . strtoupper($bank));
                }
                throw new \RuntimeException('DOKU validation error: ' . $dokuMsg);
            }
            if ($dokuMsg && str_contains(strtolower($dokuMsg), 'unauthorized')) {
                throw new \RuntimeException('Credential merchant DOKU ' . $this->merchantKey . ' tidak punya akses ke bank ' . strtoupper($bank) . '. Hubungi DOKU support.');
            }
            // Generic error dengan code untuk easier debugging
            $msg = $dokuMsg ?? 'Gagal membuat Virtual Account.';
            throw new \RuntimeException("[{$respCode}] {$msg} (bank: " . strtoupper($bank) . ", channel: {$channel})");
        }

        $result = $response->json();
        $result['_va_number']    = trim($vaNumber);
        $result['_merchant_key'] = $this->merchantKey;

        return $result;
    }

    // ── Webhook Verification ──────────────────────────────────────────────────

    public function verifyWebhook(array $headers, string $rawBody, string $webhookPath): bool
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $timestamp = $this->flattenHeader($h['x-timestamp'] ?? '');
        $signature = $this->flattenHeader($h['x-signature'] ?? '');

        if (!$timestamp || !$signature) return false;

        $body    = json_decode($rawBody, true) ?? [];
        $expected = $this->serviceSignature('POST', $webhookPath, '', $body, $timestamp);

        return hash_equals($expected, $signature);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function serviceSignature(string $method, string $path, string $accessToken, array $body, string $timestamp): string
    {
        $bodyJson    = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $bodyHash    = hash('sha256', $bodyJson); // hash() returns lowercase hex — do NOT lowercase the body before hashing
        $stringToSign = strtoupper($method) . ':' . $path . ':' . $accessToken . ':' . $bodyHash . ':' . $timestamp;
        return base64_encode(hash_hmac('sha512', $stringToSign, $this->secretKey, true));
    }

    private function timestamp(): string
    {
        return now()->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i:sP');
    }

    private function loadPrivateKey(): \OpenSSLAsymmetricKey
    {
        $pem = $this->privateKeyPem;
        if (!$pem && $this->privateKeyPath) {
            $pem = file_get_contents($this->privateKeyPath);
        }
        if (!$pem) throw new \RuntimeException('DOKU private key not configured for merchant: ' . $this->merchantKey);

        $key = openssl_pkey_get_private($pem);
        if (!$key) throw new \RuntimeException('Invalid DOKU RSA private key for merchant: ' . $this->merchantKey);
        return $key;
    }

    private function flattenHeader(mixed $v): string
    {
        return is_array($v) ? ($v[0] ?? '') : (string) $v;
    }
}
