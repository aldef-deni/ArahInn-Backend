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
 * PpobService — orchestrator transaksi PPOB.
 * - create()       : buat transaksi + VA DOKU (status pending)
 * - markPaid()     : dipanggil dari DOKU webhook saat payment success
 * - execute()      : trigger Raja Biller topup (setelah paid)
 * - refund()       : admin manual refund
 */
class PpobService
{
    public function __construct(
        private RajaBillerService $vendor,
        private PaymentService    $payment,
    ) {}

    /**
     * Buat transaksi PPOB baru.
     * Step:
     *   1. Validate product
     *   2. Inquiry untuk pascabayar (cek tagihan ke vendor)
     *   3. Hitung total bayar (price_sell + admin_fee)
     *   4. Insert ppob_transactions (status: pending)
     *   5. Generate VA DOKU
     *   6. Link payment → transaction
     */
    public function create(array $data, int $userId): array
    {
        $product = PpobProduct::with('category')->findOrFail($data['product_id']);
        if ($product->status !== 'active') {
            throw new \RuntimeException("Produk {$product->name} sedang tidak tersedia.");
        }

        $category = $product->category;
        $customerNumber = trim($data['customer_number']);
        if (empty($customerNumber)) {
            throw new \InvalidArgumentException('Nomor tujuan wajib diisi.');
        }

        // Inquiry untuk pascabayar — dapat tagihan + admin_fee + customer_name
        $customerName = null;
        $adminFee     = 0;
        $totalAmount  = (float) $product->price_sell;

        if ($category->type === 'pascabayar') {
            $inq = $this->vendor->inquiry($product->raja_biller_code, $customerNumber);
            if (!($inq['success'] ?? false)) {
                throw new \RuntimeException($inq['message'] ?? 'Inquiry tagihan gagal. Coba lagi.');
            }
            $customerName = $inq['customer_name'] ?? null;
            $adminFee     = (float) ($inq['admin_fee'] ?? 0);
            $totalAmount  = (float) ($inq['total'] ?? $product->price_sell) + $adminFee;
        }

        return DB::transaction(function () use ($product, $category, $customerNumber, $customerName, $adminFee, $totalAmount, $userId, $data) {
            $trx = PpobTransaction::create([
                'trx_code'        => PpobTransaction::generateCode(),
                'user_id'         => $userId,
                'product_id'      => $product->id,
                'category_id'     => $category->id,
                'product_name'    => $product->name,
                'product_code'    => $product->raja_biller_code,
                'customer_number' => $customerNumber,
                'customer_name'   => $customerName,
                'price_buy'       => $product->price_buy,
                'price_sell'      => $product->price_sell,
                'admin_fee'       => $adminFee,
                'total_amount'    => $totalAmount,
                'status'          => 'pending',
            ]);

            // TODO: generate VA DOKU untuk transaksi ini.
            // Untuk sekarang, return trx tanpa payment supaya skeleton lebih jelas.
            // Akan di-wire ke PaymentService.initiateForPpob($trx, $bank) di fase berikut.

            return [
                'transaction' => $trx,
                'message'     => 'Transaksi PPOB dibuat. Generate VA via /payments/initiate berikutnya.',
            ];
        });
    }

    /**
     * Dipanggil dari DOKU webhook saat payment status = settlement.
     * Idempotent: kalau sudah paid/processing, skip.
     */
    public function markPaid(PpobTransaction $trx): void
    {
        if (in_array($trx->status, ['paid', 'processing', 'success'])) {
            Log::info('PpobService::markPaid: skip (already processed)', ['trx_code' => $trx->trx_code]);
            return;
        }

        $trx->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        // Trigger eksekusi Raja Biller (sync untuk MVP, bisa di-queue nanti)
        $this->execute($trx);
    }

    /**
     * Eksekusi topup ke Raja Biller.
     * Idempotent via partner_ref = trx_code.
     */
    public function execute(PpobTransaction $trx): void
    {
        if (in_array($trx->status, ['processing', 'success'])) {
            return;
        }

        $trx->update(['status' => 'processing', 'executed_at' => now()]);

        $result = $this->vendor->topup(
            productCode: $trx->product_code,
            customerNumber: $trx->customer_number,
            partnerRef: $trx->trx_code,
        );

        if ($result['success'] ?? false) {
            $status = ($result['status'] ?? 'success') === 'pending' ? 'processing' : 'success';
            $trx->update([
                'status'              => $status,
                'raja_biller_ref'     => $result['ref_id'] ?? null,
                'serial_number'       => $result['serial'] ?? null,
                'raja_biller_payload' => $result['raw'] ?? $result,
                'completed_at'        => $status === 'success' ? now() : null,
            ]);

            if ($status === 'success') {
                $this->notifyCustomer($trx, true);
            }
        } else {
            $trx->update([
                'status'              => 'failed',
                'failure_reason'      => $result['message'] ?? 'Unknown error from Raja Biller',
                'raja_biller_payload' => $result['raw'] ?? $result,
                'completed_at'        => now(),
            ]);

            // Mark refundable — admin perlu refund manual (sesuai requirement)
            $trx->update(['status' => 'refundable']);

            $this->notifyCustomer($trx, false);
        }
    }

    /**
     * Admin manual refund.
     */
    public function refund(PpobTransaction $trx, int $adminId, ?string $notes = null): void
    {
        if (!in_array($trx->status, ['failed', 'refundable'])) {
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

    private function notifyCustomer(PpobTransaction $trx, bool $success): void
    {
        if ($success) {
            $body = "Transaksi {$trx->product_name} ke {$trx->customer_number} berhasil."
                  . ($trx->serial_number ? " Token/SN: {$trx->serial_number}" : '');
            NotificationService::send(
                $trx->user_id, 'ppob_success',
                'Transaksi Berhasil',
                $body,
                ['trx_code' => $trx->trx_code]
            );
        } else {
            NotificationService::send(
                $trx->user_id, 'ppob_failed',
                'Transaksi Gagal',
                "Transaksi {$trx->product_name} gagal. Dana akan di-refund admin segera.",
                ['trx_code' => $trx->trx_code, 'reason' => $trx->failure_reason]
            );
        }
    }
}
