<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DokuService
{
    private string $clientId;
    private string $secretKey;
    private string $baseUrl;
    private string $partnerServiceId;

    // v1.1 uses a single unified endpoint; bank is identified by CHANNEL-ID body field
    private const VA_ENDPOINT = '/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va';

    private const VA_CHANNELS = [
        'bca'     => 'VIRTUAL_ACCOUNT_BCA',
        'mandiri' => 'VIRTUAL_ACCOUNT_MANDIRI',
        'bni'     => 'VIRTUAL_ACCOUNT_BNI',
        'bri'     => 'VIRTUAL_ACCOUNT_BRI',
        'permata' => 'VIRTUAL_ACCOUNT_PERMATA',
    ];

    public function __construct()
    {
        $this->clientId         = config('services.doku.client_id');
        $this->secretKey        = config('services.doku.secret_key');
        $this->baseUrl          = rtrim(config('services.doku.base_url', 'https://api-sandbox.doku.com'), '/');
        $this->partnerServiceId = config('services.doku.partner_service_id', '');
    }

    // ── Access Token ──────────────────────────────────────────────────────────

    public function getAccessToken(): string
    {
        $cacheKey = 'doku_access_token_' . md5($this->clientId);

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
                throw new \RuntimeException('DOKU token error: ' . $response->body());
            }

            return $response->json('accessToken') ?? throw new \RuntimeException('DOKU token missing in response.');
        });
    }

    // ── Create Virtual Account ────────────────────────────────────────────────

    public function createVirtualAccount(string $bank, string $bookingCode, int $bookingId, float $amount, array $customer, \DateTimeInterface $expiresAt): array
    {
        $bank     = strtolower($bank);
        $endpoint = self::VA_ENDPOINT;
        $channel  = self::VA_CHANNELS[$bank] ?? self::VA_CHANNELS['bca'];

        $accessToken = $this->getAccessToken();
        $timestamp   = $this->timestamp();
        $externalId  = Str::uuid()->toString();

        // customerNo = Prefix Customer(1) + booking ID zero-padded to 7 digits → VA = partnerServiceId(8) + customerNo(8) = 16 chars
        $customerNo = '9' . str_pad((string) ($bookingId % 10000000), 7, '0', STR_PAD_LEFT);
        $vaNumber   = $this->partnerServiceId . $customerNo;

        $body = [
            'partnerServiceId'    => $this->partnerServiceId,
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
            Cache::forget('doku_access_token_' . md5($this->clientId));
            $body = $response->json();
            $dokuMsg = $body['error']['message'] ?? $body['message'] ?? $body['responseMessage'] ?? null;

            if ($dokuMsg && str_contains($dokuMsg, 'No static resource')) {
                throw new \RuntimeException('Layanan Virtual Account ' . strtoupper($bank) . ' belum aktif di akun DOKU. Silakan aktifkan terlebih dahulu di portal DOKU.');
            }
            throw new \RuntimeException($dokuMsg ?? 'Gagal membuat Virtual Account. Coba lagi atau pilih bank lain.');
        }

        $result = $response->json();
        $result['_va_number'] = trim($vaNumber);

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
        $pem = config('services.doku.private_key');
        if (!$pem) {
            $path = config('services.doku.private_key_path');
            $pem  = $path ? file_get_contents($path) : null;
        }
        if (!$pem) throw new \RuntimeException('DOKU private key not configured (DOKU_PRIVATE_KEY or DOKU_PRIVATE_KEY_PATH).');

        $key = openssl_pkey_get_private($pem);
        if (!$key) throw new \RuntimeException('Invalid DOKU RSA private key.');
        return $key;
    }

    private function flattenHeader(mixed $v): string
    {
        return is_array($v) ? ($v[0] ?? '') : (string) $v;
    }
}
