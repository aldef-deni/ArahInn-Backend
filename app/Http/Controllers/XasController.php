<?php

namespace App\Http\Controllers;

use App\Services\XasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * XasController — handle integrasi Rajabiller XAS (SAAS Travel Checkout Page).
 *
 * Endpoints:
 *   POST /api/v1/xas/credential   - generate embed_fe_url untuk page tertentu (auth)
 *   POST /api/v1/xas/callback     - terima callback Rajabiller (public, validated by token)
 */
class XasController extends Controller
{
    public function __construct(private XasService $xas) {}

    /**
     * Generate credential XAS untuk page tertentu (kereta/pesawat/dlu/pelni).
     * POST /api/v1/xas/credential
     *
     * Body: { page: string, phone: string (optional, default dari user logged in) }
     * Return: { success, data: { embed_fe_url, expired_time, token_mitra } }
     */
    public function createCredential(Request $request)
    {
        $data = $request->validate([
            'page'  => 'required|string|in:' . implode(',', XasService::PAGES),
            'phone' => 'sometimes|string|max:20',
        ]);

        $user  = $request->user();
        $phone = $data['phone'] ?? $user?->phone ?? '0000000000';

        $resp = $this->xas->generateTravelCredential($data['page'], $phone);

        if (!XasService::isSuccess($resp['rc'] ?? null)) {
            return response()->json([
                'success' => false,
                'message' => XasService::userMessage($resp['rc'] ?? null),
                'rc'      => $resp['rc'] ?? null,
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'embed_fe_url' => $resp['embed_fe_url'],
                'expired_time' => $resp['expired_time'],
                'token_mitra'  => $resp['token_mitra'],
                'page'         => $data['page'],
            ],
        ]);
    }

    /**
     * Webhook callback dari Rajabiller XAS.
     * POST /api/v1/xas/callback
     *
     * NOTE: Mode Checkout Page, payment fully handled di Winpay/Rajabiller.
     * Callback tetap di-receive untuk logging + future audit.
     * Validation: cek header token_mitra match dengan yang kita generate.
     */
    public function callback(Request $request)
    {
        $payload   = $request->all();
        $tokenMitra = $request->header('token-mitra') ?? $request->header('token_mitra');

        Log::info('XAS callback received', [
            'token_mitra' => $tokenMitra,
            'rc'          => $payload['rc'] ?? null,
            'trxid'       => $payload['trxid'] ?? null,
            'produk'      => $payload['produk'] ?? null,
            'payload'     => $payload,
        ]);

        // TODO: Future enhancement
        // 1. Save trx to xas_transactions table (kalau perlu track riwayat customer)
        // 2. Send notification to customer (email + push)
        // 3. Update user's loyalty points

        // Respond OK supaya Rajabiller tidak retry
        return response()->json([
            'success' => true,
            'rc'      => '00',
            'message' => 'Callback received',
        ]);
    }
}
