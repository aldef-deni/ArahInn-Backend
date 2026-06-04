<?php

namespace App\Http\Controllers;

use App\Services\PpobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint untuk async callback dari Rajabiller.
 *
 * 2 jenis callback yang Rajabiller kirim:
 *
 * 1. TRANSACTION CALLBACK — saat trx pending (RC 68) finalized.
 *    Payload structure = sama dengan response method 'bayar'.
 *    Endpoint: POST /api/v1/ppob/callback/rajabiller/transaction
 *
 * 2. PRODUCT INFO CALLBACK — saat ada perubahan harga/admin/status produk.
 *    Payload: { produk, uid, rc, harga, admin, komisi, nama_produk, status_produk }
 *    Endpoint: POST /api/v1/ppob/callback/rajabiller/product-info
 *
 * Kedua endpoint:
 *   - Protected by IP whitelist middleware (34.128.119.54 production)
 *   - Method POST only
 *   - Body JSON
 *   - Return 200 + { rc: '00', msg: 'OK' } setelah berhasil
 */
class PpobCallbackController extends Controller
{
    public function __construct(
        private PpobService $service,
    ) {}

    /**
     * Transaction callback (sukses/gagal final setelah RC 68).
     */
    public function transaction(Request $request)
    {
        $payload = $request->all();

        // Validate UID match
        $expectedUid = config('services.raja_biller.uid');
        $payloadUid  = $payload['uid'] ?? null;
        if ($expectedUid && $payloadUid && $payloadUid !== $expectedUid) {
            Log::warning('Rajabiller transaction callback UID mismatch', [
                'expected' => $expectedUid,
                'got'      => $payloadUid,
                'payload'  => $payload,
            ]);
            return response()->json(['rc' => '01', 'msg' => 'Invalid UID'], 401);
        }

        $handled = $this->service->handleTransactionCallback($payload);

        return response()->json([
            'rc'  => '00',
            'msg' => $handled ? 'OK' : 'Acknowledged (no-op)',
        ]);
    }

    /**
     * Product info callback (catalog harga/status update).
     */
    public function productInfo(Request $request)
    {
        $payload = $request->validate([
            'produk'        => 'required|string|max:20',
            'uid'           => 'required|string',
            'rc'            => 'nullable|string',
            'harga'         => 'nullable|numeric',
            'admin'         => 'nullable|numeric',
            'komisi'        => 'nullable|numeric',
            'nama_produk'   => 'nullable|string',
            'status_produk' => 'required|string',
        ]);

        // Validate UID
        $expectedUid = config('services.raja_biller.uid');
        if ($expectedUid && $payload['uid'] !== $expectedUid) {
            return response()->json(['rc' => '01', 'msg' => 'Invalid UID'], 401);
        }

        $handled = $this->service->handleProductInfoCallback($payload);

        return response()->json([
            'rc'  => '00',
            'msg' => $handled ? 'OK' : 'Product not in catalog (acknowledged)',
        ]);
    }
}
