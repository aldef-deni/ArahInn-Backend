<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PpobCategory;
use App\Models\PpobProduct;
use App\Models\PpobTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PpobService — orchestrator transaksi PPOB Rajabiller.
 *
 * Flow per tipe produk (per doctek Rajabiller):
 *
 * PREPAID (Pulsa, Data, E-Wallet, Game Voucher):
 *   1. user pilih produk + idpel
 *   2. createPrepaidTransaction() → call bayar() langsung
 *   3. parse response → update tx state (success/pending/failed)
 *   4. kalau pending: tunggu callback async via PpobCallbackController
 *
 * POSTPAID (PLN Prabayar, PLN Pasca, PDAM, BPJS, dll):
 *   1. user pilih produk + idpel
 *   2. createInquiry() → call cek() → return tagihan ke user
 *   3. user konfirmasi → confirmPay() → call bayar() pakai ref1 SAMA
 *   4. parse response → update tx state
 *   5. kalau pending: tunggu callback async
 */
class PpobService
{
    public function __construct(
        private RajaBillerService $vendor,
    ) {}

    /* ────────────────────────────────────────────────────────────────────
     | PREPAID flow — single step
     |
     | $extra optional sesuai produk:
     |   PLN Prabayar : ['nominal' => '20000']  ← walau "prabayar", treat seperti POSTPAID 2-step karena perlu cek customer dulu
     |   E-Money Open Denom : ['nominal' => '50000']
     ──────────────────────────────────────────────────────────────────── */
    public function createPrepaidTransaction(int $userId, int $productId, string $idpel, array $extra = []): PpobTransaction
    {
        $product = PpobProduct::with('category')->findOrFail($productId);

        if (!$product->isAvailable()) {
            throw new \RuntimeException("Produk {$product->name} sedang tidak tersedia ({$product->status_label}).");
        }

        // NOTE: flow saat ini = manual transfer.
        // Setelah create, user transfer ke rekening. Admin verifikasi mutasi → mark-paid → executePayment ke Rajabiller.
        $expiresHours = (int) (config('services.payment.manual_expires_hours') ?? env('PAYMENT_MANUAL_EXPIRES_HOURS', 24));

        $trx = DB::transaction(function () use ($userId, $product, $idpel, $extra, $expiresHours) {
            // Default: pakai snapshot harga dari catalog
            $adminFee   = (float) $product->admin_fee;
            $priceBuy   = (float) $product->price_buy;
            $priceSell  = (float) $product->price_sell;
            $totalAmount = $priceSell;

            // Khusus produk dengan nominal variable (PLN Prabayar, EMONEY open denom):
            // user supply extra.nominal → total = nominal + admin + markup kecil (0.5% min 500).
            if (!empty($extra['nominal']) && is_numeric($extra['nominal'])) {
                $nominal     = (float) $extra['nominal'];
                $markup      = (int) max(500, $nominal * 0.005); // 0.5% min 500
                $priceBuy    = $nominal + $adminFee;
                $priceSell   = $priceBuy + $markup;
                $totalAmount = $priceSell;
            }

            return PpobTransaction::create([
                'trx_code'        => PpobTransaction::generateCode(),
                'ref1'            => PpobTransaction::generateRef1(),
                'user_id'         => $userId,
                'product_id'      => $product->id,
                'category_id'     => $product->category_id,
                'product_name'    => $product->name,
                'product_code'    => $product->raja_biller_code,
                'customer_number' => $idpel,
                'extra_request'   => $extra,
                'price_buy'       => $priceBuy,
                'price_sell'      => $priceSell,
                'admin_fee'       => $adminFee,
                'total_amount'    => $totalAmount,
                'status'          => 'pending',
                'expires_at'      => now()->addHours($expiresHours),
            ]);
        });

        // Variable-nominal products (PLN Prabayar, E-Wallet open denom) butuh cek() dulu
        // untuk register inquiry session sebelum bayar() bisa dipanggil.
        // Vendor return:
        //   - "data inquiry tidak ditemukan" kalau bayar tanpa cek prior
        //   - "nominal tidak sesuai dengan data inquiry" kalau nominal mismatch
        if ($this->productNeedsInquiry($product, $extra)) {
            $this->prepareVendorInquiry($trx);
        }

        return $trx->fresh();
    }

    /**
     * Detect produk yang butuh `cek()` step dulu sebelum `bayar()` (2-step flow).
     * Rule: produk dengan nominal variable (extra.nominal di-supply) — PLN Prabayar
     * dan E-Wallet open denom (GoPay/OVO/DANA/Shopee/dll) semua match pattern ini.
     */
    private function productNeedsInquiry(PpobProduct $product, array $extra = []): bool
    {
        // Primary signal: extra.nominal ada → variable nominal → vendor butuh inquiry session
        if (!empty($extra['nominal']) && is_numeric($extra['nominal'])) {
            return true;
        }
        // Fallback explicit: PLN Prabayar (kalau extra ga ke-include karena edge case)
        $code = strtoupper($product->raja_biller_code ?? '');
        return str_starts_with($code, 'PLNPRA');
    }

    /**
     * Call vendor cek() untuk register inquiry session.
     * Hasil disimpan ke inquiry_response + customer_name.
     * Kalau cek gagal → mark trx failed langsung (sebelum user transfer).
     */
    private function prepareVendorInquiry(PpobTransaction $trx): void
    {
        $resp = $this->vendor->cek(
            $trx->product_code,
            $trx->customer_number,
            $trx->ref1,
            $trx->extra_request ?? []
        );

        $normalized = $this->vendor->extractCommonFields($resp);

        $trx->update([
            'rc'                     => $normalized['rc'],
            'inquiry_response'       => $resp,
            'inquired_at'            => now(),
            'customer_name'          => $normalized['nama'] ?? null,
            'saldo_akhir_rajabiller' => $normalized['saldo_akhir'] ?? null,
        ]);

        if (RajaBillerService::isFailed($normalized['rc'])) {
            $trx->update([
                'status'         => 'failed',
                'failure_reason' => RajaBillerService::userMessage($normalized['rc']),
                'completed_at'   => now(),
            ]);
            throw new \RuntimeException('Inquiry vendor gagal: ' . RajaBillerService::userMessage($normalized['rc']));
        }
    }

    /* ────────────────────────────────────────────────────────────────────
     | POSTPAID flow Step 1 — Inquiry (cek)
     |
     | Return tx dengan status 'pending' + inquiry data.
     | User lihat tagihan, lalu confirm via confirmPostpaidPay().
     ──────────────────────────────────────────────────────────────────── */
    public function createInquiry(int $userId, int $productId, string $idpel, array $extra = []): PpobTransaction
    {
        $product = PpobProduct::with('category')->findOrFail($productId);

        if (!$product->isAvailable()) {
            throw new \RuntimeException("Produk {$product->name} sedang tidak tersedia ({$product->status_label}).");
        }

        return DB::transaction(function () use ($userId, $product, $idpel, $extra) {
            $trx = PpobTransaction::create([
                'trx_code'        => PpobTransaction::generateCode(),
                'ref1'            => PpobTransaction::generateRef1(),
                'user_id'         => $userId,
                'product_id'      => $product->id,
                'category_id'     => $product->category_id,
                'product_name'    => $product->name,
                'product_code'    => $product->raja_biller_code,
                'customer_number' => $idpel,
                'extra_request'   => $extra,
                'price_buy'       => 0,    // akan di-set setelah inquiry response
                'price_sell'      => 0,
                'admin_fee'       => 0,
                'total_amount'    => 0,
                'status'          => 'pending',
                'expires_at'      => now()->addMinutes(5), // user must confirm dalam 5 menit
            ]);

            $resp = $this->vendor->cek(
                $product->raja_biller_code,
                $idpel,
                $trx->ref1,
                $extra
            );

            $normalized = $this->vendor->extractCommonFields($resp);

            $trx->update([
                'rc'                     => $normalized['rc'],
                'inquiry_response'       => $resp,
                'inquired_at'            => now(),
                'customer_name'          => $normalized['nama'],
                'saldo_akhir_rajabiller' => $normalized['saldo_akhir'],
            ]);

            // Kalau inquiry GAGAL → mark failed
            if (RajaBillerService::isFailed($normalized['rc'])) {
                $trx->update([
                    'status'         => 'failed',
                    'failure_reason' => RajaBillerService::userMessage($normalized['rc']),
                    'completed_at'   => now(),
                ]);
                return $trx;
            }

            // Inquiry SUKSES → set tagihan + admin
            $tagihan = $normalized['tagihan']     ?? 0;
            $admin   = $normalized['adm']         ?? $product->admin_fee;
            $total   = $normalized['total_bayar'] ?? ($tagihan + $admin);

            $trx->update([
                'price_buy'   => $tagihan + $admin,  // cost ke Rajabiller
                'price_sell'  => $total,             // user bayar
                'admin_fee'   => $admin,
                'total_amount'=> $total,
                'status'      => 'pending', // waiting user confirm
            ]);

            return $trx;
        });
    }

    /* ────────────────────────────────────────────────────────────────────
     | POSTPAID Step 2 — Confirm Pay
     |
     | User sudah lihat tagihan, sekarang bayar. Pakai ref1 SAMA dengan inquiry.
     ──────────────────────────────────────────────────────────────────── */
    public function confirmPostpaidPay(PpobTransaction $trx): PpobTransaction
    {
        if (!$trx->isInquired()) {
            throw new \RuntimeException('Transaksi belum di-inquiry.');
        }
        if ($trx->isExpired()) {
            $trx->update(['status' => 'canceled', 'failure_reason' => 'Inquiry expired']);
            throw new \RuntimeException('Tagihan sudah kadaluwarsa, mohon cek ulang.');
        }
        if ($trx->status !== 'pending') {
            throw new \RuntimeException("Status transaksi {$trx->status} — tidak bisa dikonfirmasi.");
        }

        // Manual transfer flow: user confirm tagihan → buka window pembayaran 24 jam.
        // Rajabiller bayar dieksekusi nanti saat admin mark-paid.
        $expiresHours = (int) (config('services.payment.manual_expires_hours') ?? env('PAYMENT_MANUAL_EXPIRES_HOURS', 24));

        $trx->update([
            'status'     => 'pending',
            'expires_at' => now()->addHours($expiresHours),
        ]);

        return $trx->fresh();
    }

    /* ────────────────────────────────────────────────────────────────────
     | Admin mark-paid — verifikasi manual transfer, lanjut eksekusi Rajabiller.
     ──────────────────────────────────────────────────────────────────── */
    public function adminMarkPaidAndExecute(PpobTransaction $trx, int $adminId, ?string $notes = null): PpobTransaction
    {
        if (!in_array($trx->status, ['pending'], true)) {
            throw new \RuntimeException("Status {$trx->status} — tidak bisa di-mark-paid.");
        }

        $trx->update([
            'status'       => 'processing',
            'paid_at'      => now(),
            'executed_at'  => now(),
            'paid_by'      => $adminId,
            'paid_notes'   => $notes,
        ]);

        $this->executePayment($trx, $trx->extra_request ?? []);

        return $trx->fresh();
    }

    /* ────────────────────────────────────────────────────────────────────
     | Re-hit transaksi gagal/refundable ke Rajabiller.
     | Pakai ref1 BARU supaya tidak dianggap duplikat (RC 97) oleh vendor —
     | sesuai instruksi Rajabiller "silakan hit transaksi baru saat produk
     | sudah open kembali". Reset rc/sn/failure_reason lalu eksekusi ulang.
     ──────────────────────────────────────────────────────────────────── */
    public function reHit(PpobTransaction $trx, int $adminId, ?string $notes = null): PpobTransaction
    {
        if (!in_array($trx->status, ['failed', 'refundable', 'paid'], true)) {
            throw new \RuntimeException("Status {$trx->status} tidak dapat di-re-hit.");
        }

        $trx->update([
            'ref1'           => PpobTransaction::generateRef1(),
            'status'         => 'processing',
            'rc'             => null,
            'serial_number'  => null,
            'failure_reason' => null,
            'executed_at'    => now(),
            'paid_by'        => $adminId,
            'paid_notes'     => $notes ?? 'Manual re-hit',
        ]);

        $this->executePayment($trx, $trx->extra_request ?? []);

        return $trx->fresh();
    }

    /* ────────────────────────────────────────────────────────────────────
     | Eksekusi bayar() — shared antara prepaid + postpaid step 2.
     ──────────────────────────────────────────────────────────────────── */
    private function executePayment(PpobTransaction $trx, array $extra): void
    {
        $resp = $this->vendor->bayar(
            $trx->product_code,
            $trx->customer_number,
            $trx->ref1,
            $extra
        );

        $normalized = $this->vendor->extractCommonFields($resp);

        // Persist response + normalized fields
        $trx->update([
            'rc'                     => $normalized['rc'],
            'payment_response'       => $resp,
            'raja_biller_ref'        => $normalized['refid'],
            'serial_number'          => $normalized['sn'],
            'template_struk'         => $normalized['template_struk'],
            'struk_url'              => $normalized['struk_url'],
            'saldo_akhir_rajabiller' => $normalized['saldo_akhir'],
            'customer_name'          => $trx->customer_name ?? $normalized['nama'],
        ]);

        $this->handlePaymentResponse($trx, $normalized, $resp);
    }

    /* ────────────────────────────────────────────────────────────────────
     | Handler response state machine.
     |
     | Per doctek:
     |   RC 00          → SUCCESS, deliver to customer
     |   RC 68          → PENDING, tunggu callback
     |   RC other       → FAILED, refund
     |   HTTP timeout   → PENDING (treat sebagai async)
     |   HTTP 401/403/429 → FAILED
     ──────────────────────────────────────────────────────────────────── */
    private function handlePaymentResponse(PpobTransaction $trx, array $normalized, array $rawResp): void
    {
        $rc      = $normalized['rc'];
        $treatAs = $rawResp['_treat_as'] ?? 'normal';

        // Connection/timeout/5xx → set processing, will be resolved by callback or status check
        if ($treatAs === 'pending') {
            $trx->update(['status' => 'processing']);
            Log::info('PPOB tx in processing (HTTP unknown)', [
                'trx_code' => $trx->trx_code,
                'ref1'     => $trx->ref1,
                'http'     => $rawResp['_http_status'] ?? null,
            ]);
            return;
        }

        // RC 00 → SUCCESS
        if (RajaBillerService::isSuccess($rc)) {
            $trx->update([
                'status'       => 'success',
                'completed_at' => now(),
            ]);
            $this->notifyCustomerSuccess($trx);
            return;
        }

        // RC 68 → PENDING (tunggu callback)
        if (RajaBillerService::isPending($rc)) {
            $trx->update(['status' => 'processing']);
            Log::info('PPOB tx pending RC 68 — waiting callback', [
                'trx_code' => $trx->trx_code,
                'ref1'     => $trx->ref1,
            ]);
            return;
        }

        // RC bukan 68 tapi pesan vendor "SEDANG DIPROSES" dsb → perlakukan sebagai
        // PENDING (tunggu callback), JANGAN refundable. Cegah refund prematur →
        // double-payout kalau ternyata transaksi tetap diselesaikan vendor.
        if (RajaBillerService::isProcessingMessage($rawResp['status'] ?? null)) {
            $trx->update(['status' => 'processing']);
            Log::warning('PPOB rc-failure tapi pesan vendor indikasi diproses — tahan sbg processing', [
                'trx_code' => $trx->trx_code,
                'rc'       => $rc,
                'message'  => $rawResp['status'] ?? null,
            ]);
            return;
        }

        // RC failure → mark failed + refundable
        $trx->update([
            'status'         => 'refundable',
            'failure_reason' => RajaBillerService::userMessage($rc),
            'completed_at'   => now(),
        ]);

        $this->notifyCustomerFailed($trx);

        // Alert admin untuk RC critical (saldo habis, biller down, dll)
        if (RajaBillerService::shouldAlertAdmin($rc)) {
            Log::critical('PPOB critical RC needs admin attention', [
                'trx_code' => $trx->trx_code,
                'rc'       => $rc,
                'message'  => $rawResp['status'] ?? null,
            ]);
        }
    }

    /* ────────────────────────────────────────────────────────────────────
     | Handle async callback dari Rajabiller (transaction callback).
     | Idempotent: skip kalau tx sudah final.
     ──────────────────────────────────────────────────────────────────── */
    public function handleTransactionCallback(array $payload): bool
    {
        $ref1 = $payload['ref1'] ?? $payload['trxid'] ?? null;
        if (!$ref1) {
            Log::warning('PPOB callback tanpa ref1', $payload);
            return false;
        }

        $trx = PpobTransaction::where('ref1', $ref1)->first();
        if (!$trx) {
            Log::warning('PPOB callback untuk tx tidak ditemukan', ['ref1' => $ref1]);
            return false;
        }

        // Idempotent — kalau sudah final, ignore (tapi tetap simpan callback_data)
        if (in_array($trx->status, ['success', 'refunded', 'canceled'], true)) {
            $trx->update([
                'callback_data'        => $payload,
                'callback_received_at' => now(),
            ]);
            Log::info('PPOB callback received but tx already final', [
                'trx_code' => $trx->trx_code,
                'status'   => $trx->status,
            ]);
            return true;
        }

        $normalized = $this->vendor->extractCommonFields($payload);
        $rc = $normalized['rc'];

        $trx->update([
            'rc'                     => $rc,
            'callback_data'          => $payload,
            'callback_received_at'   => now(),
            'serial_number'          => $normalized['sn'] ?? $trx->serial_number,
            'template_struk'         => $normalized['template_struk'] ?? $trx->template_struk,
            'struk_url'              => $normalized['struk_url'] ?? $trx->struk_url,
            'saldo_akhir_rajabiller' => $normalized['saldo_akhir'] ?? $trx->saldo_akhir_rajabiller,
            'raja_biller_ref'        => $normalized['refid'] ?? $trx->raja_biller_ref,
        ]);

        if (RajaBillerService::isSuccess($rc)) {
            $trx->update(['status' => 'success', 'completed_at' => now()]);
            $this->notifyCustomerSuccess($trx);
        } elseif (RajaBillerService::isProcessingMessage($payload['status'] ?? null)) {
            // Vendor masih memproses → tetap processing, tunggu callback final.
            $trx->update(['status' => 'processing']);
            Log::warning('PPOB callback rc-failure tapi pesan indikasi diproses — tahan sbg processing', [
                'trx_code' => $trx->trx_code,
                'rc'       => $rc,
                'message'  => $payload['status'] ?? null,
            ]);
        } elseif (RajaBillerService::isFailed($rc)) {
            $trx->update([
                'status'         => 'refundable',
                'failure_reason' => RajaBillerService::userMessage($rc),
                'completed_at'   => now(),
            ]);
            $this->notifyCustomerFailed($trx);
        }

        return true;
    }

    /* ────────────────────────────────────────────────────────────────────
     | Handle product info callback (catalog update).
     ──────────────────────────────────────────────────────────────────── */
    public function handleProductInfoCallback(array $payload): bool
    {
        $code = $payload['produk'] ?? null;
        if (!$code) return false;

        $product = PpobProduct::where('raja_biller_code', $code)->first();
        if (!$product) {
            Log::info('PPOB info callback untuk produk tidak ada di catalog (skipped)', ['produk' => $code]);
            return false;
        }

        $statusLbl = $payload['status_produk'] ?? $product->status_label;
        $status = match (true) {
            str_contains($statusLbl, 'CLOSE')    => 'inactive',
            str_contains($statusLbl, 'GANGGUAN') => 'gangguan',
            default                              => 'active',
        };

        $product->update([
            'name'              => $payload['nama_produk'] ?? $product->name,
            'price_buy'         => (float) ($payload['harga']  ?? $product->price_buy),
            'admin_fee'         => (float) ($payload['admin']  ?? $product->admin_fee),
            'komisi'            => (float) ($payload['komisi'] ?? $product->komisi),
            'status'            => $status,
            'status_label'      => $statusLbl,
            'last_callback_at'  => now(),
        ]);

        return true;
    }

    /* ────────────────────────────────────────────────────────────────────
     | Admin cancel — batalkan transaksi yang belum dibayar.
     ──────────────────────────────────────────────────────────────────── */
    public function cancelTransaction(PpobTransaction $trx, int $adminId, ?string $notes = null): void
    {
        if (!in_array($trx->status, ['pending'], true)) {
            throw new \RuntimeException("Status {$trx->status} — tidak bisa dibatalkan (hanya yang belum dibayar).");
        }

        $trx->update([
            'status'         => 'canceled',
            'failure_reason' => $notes ?: 'Dibatalkan admin',
            'paid_by'        => $adminId,
            'paid_notes'     => $notes,
            'completed_at'   => now(),
        ]);

        NotificationService::send(
            $trx->user_id, 'ppob_canceled',
            'Transaksi Dibatalkan',
            "Transaksi {$trx->trx_code} ({$trx->product_name}) telah dibatalkan oleh admin.",
            ['trx_code' => $trx->trx_code, 'reason' => $notes]
        );
    }

    /* ────────────────────────────────────────────────────────────────────
     | Admin refund
     ──────────────────────────────────────────────────────────────────── */
    public function refund(PpobTransaction $trx, int $adminId, ?string $notes = null): void
    {
        if (!in_array($trx->status, ['failed', 'refundable'], true)) {
            throw new \RuntimeException("Trx tidak bisa di-refund (status: {$trx->status}).");
        }

        $trx->update([
            'status'       => 'refunded',
            'refunded_by'  => $adminId,
            'refunded_at'  => now(),
            'refund_notes' => $notes,
        ]);

        NotificationService::send(
            $trx->user_id, 'ppob_refunded',
            'Refund Diproses',
            "Transaksi {$trx->trx_code} ({$trx->product_name}) telah di-refund.",
            ['trx_code' => $trx->trx_code]
        );
    }

    /* ────────────────────────────────────────────────────────────────────
     | Notifications
     ──────────────────────────────────────────────────────────────────── */
    private function notifyCustomerSuccess(PpobTransaction $trx): void
    {
        $body = "Transaksi {$trx->product_name} ke {$trx->customer_number} berhasil.";
        if ($trx->serial_number) {
            $body .= " Token/SN: {$trx->serial_number}";
        }

        NotificationService::send(
            $trx->user_id, 'ppob_success',
            'Transaksi Berhasil',
            $body,
            [
                'trx_code'  => $trx->trx_code,
                'sn'        => $trx->serial_number,
                'struk_url' => $trx->struk_url,
            ]
        );

        // Email + lampiran PDF e-struk (tidak boleh menggagalkan flow utama)
        $email = optional($trx->user)->email;
        if ($email) {
            try {
                \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\PpobSuccessMail($trx));
            } catch (\Throwable $e) {
                Log::error('PPOB e-struk email gagal', ['trx_code' => $trx->trx_code, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notifyCustomerFailed(PpobTransaction $trx): void
    {
        NotificationService::send(
            $trx->user_id, 'ppob_failed',
            'Transaksi Gagal',
            "Transaksi {$trx->product_name} gagal. Dana akan di-refund admin segera.",
            ['trx_code' => $trx->trx_code, 'reason' => $trx->failure_reason]
        );
    }
}
