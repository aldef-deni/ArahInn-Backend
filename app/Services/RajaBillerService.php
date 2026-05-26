<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SKELETON RajaBillerService — actual API call belum diisi.
 * Akan di-implement penuh setelah docs Raja Biller masuk.
 *
 * Asumsi awal struktur (umum untuk PPOB aggregator Indonesia):
 *   - REST API dengan signature HMAC SHA256 atau username/password + ApiKey
 *   - Endpoint utama: inquiry, topup/payment, check-status, product-list, balance
 *   - Webhook untuk async update status transaksi
 *
 * Setelah docs masuk, lengkapi:
 *   - buildHeaders() dengan format auth Raja Biller
 *   - URL endpoint
 *   - Payload format
 *   - Response parsing
 */
class RajaBillerService
{
    private string $baseUrl;
    private string $apiKey;
    private string $secret;
    private string $username;
    private bool   $isSandbox;

    public function __construct()
    {
        $this->baseUrl   = rtrim(config('services.raja_biller.base_url', ''), '/');
        $this->apiKey    = (string) config('services.raja_biller.api_key', '');
        $this->secret    = (string) config('services.raja_biller.secret', '');
        $this->username  = (string) config('services.raja_biller.username', '');
        $this->isSandbox = (bool)   config('services.raja_biller.sandbox', true);
    }

    /**
     * Get account balance dari Raja Biller.
     * TODO: replace dengan endpoint sebenarnya setelah docs masuk.
     */
    public function getBalance(): array
    {
        // STUB — return dummy balance untuk dev
        if ($this->isSandbox && empty($this->apiKey)) {
            return ['success' => true, 'balance' => 999999999, 'currency' => 'IDR', '_stub' => true];
        }

        return $this->call('GET', '/balance');
    }

    /**
     * Get list produk yang tersedia (untuk sync ke ppob_products table).
     * TODO: replace dengan endpoint sebenarnya.
     *
     * Expected return:
     *   ['success' => true, 'data' => [['code' => 'T5', 'name' => '...', 'price' => 5000, ...], ...]]
     */
    public function getProducts(?string $categoryCode = null): array
    {
        if ($this->isSandbox && empty($this->apiKey)) {
            return ['success' => true, 'data' => [], '_stub' => true];
        }

        return $this->call('GET', '/products', ['category' => $categoryCode]);
    }

    /**
     * Inquiry tagihan (untuk pascabayar — cek tagihan dulu sebelum bayar).
     * Contoh: cek tagihan PLN postpaid, BPJS, PDAM.
     *
     * @return array{success: bool, customer_name?: string, total?: float, admin_fee?: float, ref_id?: string, raw?: array}
     */
    public function inquiry(string $productCode, string $customerNumber): array
    {
        if ($this->isSandbox && empty($this->apiKey)) {
            // Stub response untuk test UI flow
            return [
                'success'       => true,
                'customer_name' => 'Test Customer (Stub)',
                'total'         => 150000,
                'admin_fee'     => 2500,
                'ref_id'        => 'INQ-STUB-' . substr(uniqid(), -8),
                '_stub'         => true,
            ];
        }

        return $this->call('POST', '/inquiry', [
            'product_code'    => $productCode,
            'customer_number' => $customerNumber,
        ]);
    }

    /**
     * Eksekusi topup / payment ke Raja Biller.
     * Dipanggil SETELAH customer bayar VA (DOKU webhook trigger).
     *
     * @param string $productCode     Kode produk Raja Biller
     * @param string $customerNumber  Nomor tujuan (HP / PLN / dll)
     * @param string $partnerRef      Reference dari sisi kita (PPOB trx_code) — untuk idempotency
     * @return array{success: bool, status: string, ref_id?: string, serial?: string, raw?: array}
     */
    public function topup(string $productCode, string $customerNumber, string $partnerRef): array
    {
        if ($this->isSandbox && empty($this->apiKey)) {
            // Stub: 90% sukses, 10% pending (untuk simulate retry flow)
            $success = random_int(1, 100) <= 90;
            return [
                'success' => $success,
                'status'  => $success ? 'success' : 'pending',
                'ref_id'  => 'RB-STUB-' . substr(uniqid(), -10),
                'serial'  => $success ? 'SN-' . strtoupper(bin2hex(random_bytes(8))) : null,
                'message' => $success ? 'Transaksi sukses (stub)' : 'Transaksi sedang diproses (stub)',
                '_stub'   => true,
            ];
        }

        return $this->call('POST', '/topup', [
            'product_code'    => $productCode,
            'customer_number' => $customerNumber,
            'partner_ref'     => $partnerRef,
        ]);
    }

    /**
     * Check status transaksi yang masih pending di Raja Biller.
     * Dipanggil via polling untuk transaksi async.
     */
    public function checkStatus(string $partnerRef): array
    {
        if ($this->isSandbox && empty($this->apiKey)) {
            return ['success' => true, 'status' => 'success', '_stub' => true];
        }

        return $this->call('GET', "/transaction/{$partnerRef}/status");
    }

    /**
     * Wrapper HTTP call dengan auth headers + error handling.
     * TODO: sesuaikan signature/auth setelah docs masuk.
     */
    private function call(string $method, string $endpoint, array $body = []): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Raja Biller belum di-konfigurasi. Set RAJA_BILLER_* di .env.',
            ];
        }

        try {
            $headers = $this->buildHeaders($endpoint, $body);
            $url     = $this->baseUrl . $endpoint;

            $request = Http::withHeaders($headers)->timeout(30);

            $response = match (strtoupper($method)) {
                'GET'  => $request->get($url, $body),
                'POST' => $request->post($url, $body),
                default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
            };

            $payload = $response->json() ?? [];

            if (!$response->successful()) {
                Log::warning('RajaBiller API error', [
                    'endpoint' => $endpoint,
                    'status'   => $response->status(),
                    'body'     => $payload,
                ]);
                return [
                    'success' => false,
                    'message' => $payload['message'] ?? "HTTP {$response->status()}",
                    'raw'     => $payload,
                ];
            }

            return array_merge(['success' => true, 'raw' => $payload], $payload);

        } catch (\Throwable $e) {
            Log::error('RajaBiller call exception', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * TODO: sesuaikan dengan spec auth Raja Biller setelah docs masuk.
     * Pola umum: signature HMAC SHA256 dari (timestamp + apikey + endpoint + body).
     */
    private function buildHeaders(string $endpoint, array $body): array
    {
        $timestamp = (string) round(microtime(true) * 1000);
        $bodyStr   = json_encode($body, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $this->apiKey . $timestamp . $endpoint . $bodyStr, $this->secret);

        return [
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
            'X-Api-Key'       => $this->apiKey,
            'X-Username'      => $this->username,
            'X-Timestamp'     => $timestamp,
            'X-Signature'     => $signature,
        ];
    }
}
