<?php

namespace App\Http\Controllers;

use App\Models\PpobCategory;
use App\Models\PpobProduct;
use App\Models\PpobTransaction;
use App\Services\PpobService;
use App\Services\RajaBillerService;
use Illuminate\Http\Request;

class PpobController extends Controller
{
    public function __construct(
        private PpobService       $ppob,
        private RajaBillerService $vendor,
    ) {}

    /* ──────────────────────────────────────────────────────────────────
     | Public — Catalog browsing
     ────────────────────────────────────────────────────────────────── */

    public function categories()
    {
        $categories = PpobCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function products(Request $request)
    {
        $query = PpobProduct::available()->with('category:id,code,name,group,type');

        if ($categoryCode = $request->query('category')) {
            $cat = PpobCategory::where('code', $categoryCode)->first();
            if (!$cat) return response()->json(['success' => true, 'data' => []]);
            $query->where('category_id', $cat->id);
        }
        if ($operator = $request->query('operator')) {
            $query->where('operator', $operator);
        }
        if ($search = $request->query('q')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $products = $query->orderBy('nominal')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $products]);
    }

    /* ──────────────────────────────────────────────────────────────────
     | Authenticated — Transaction flow
     ────────────────────────────────────────────────────────────────── */

    /**
     * PREPAID: purchase langsung (Pulsa, E-Wallet, Game Voucher non-PLN).
     * POST /api/v1/ppob/purchase
     */
    public function purchase(Request $request)
    {
        $data = $request->validate([
            'product_id'      => 'required|integer|exists:ppob_products,id',
            'customer_number' => 'required|string|max:50',
            'extra'           => 'sometimes|array',
        ]);

        try {
            $trx = $this->ppob->createPrepaidTransaction(
                userId    : $request->user()->id,
                productId : $data['product_id'],
                idpel     : $data['customer_number'],
                extra     : $data['extra'] ?? [],
            );

            return response()->json([
                'success' => true,
                'data'    => $this->presentTransaction($trx),
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POSTPAID + PLN: Step 1 inquiry — cek tagihan/customer.
     * POST /api/v1/ppob/inquiry
     */
    public function inquiry(Request $request)
    {
        $data = $request->validate([
            'product_id'      => 'required|integer|exists:ppob_products,id',
            'customer_number' => 'required|string|max:50',
            'extra'           => 'sometimes|array',  // nominal (PLN), periode (BPJS), tahun (PBB)
        ]);

        try {
            $trx = $this->ppob->createInquiry(
                userId    : $request->user()->id,
                productId : $data['product_id'],
                idpel     : $data['customer_number'],
                extra     : $data['extra'] ?? [],
            );

            return response()->json([
                'success' => true,
                'data'    => $this->presentTransaction($trx),
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POSTPAID Step 2: confirm + pay.
     * POST /api/v1/ppob/transactions/{trxCode}/confirm-pay
     */
    public function confirmPay(Request $request, string $trxCode)
    {
        $trx = PpobTransaction::where('trx_code', $trxCode)->firstOrFail();

        $user = $request->user();
        if ((int) $trx->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        try {
            $trx = $this->ppob->confirmPostpaidPay($trx);
            return response()->json([
                'success' => true,
                'data'    => $this->presentTransaction($trx),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/v1/ppob/transactions/{trxCode}
     */
    public function show(string $trxCode, Request $request)
    {
        $trx = PpobTransaction::with(['product:id,name', 'category:id,name,type'])
            ->where('trx_code', $trxCode)
            ->firstOrFail();

        $user = $request->user();
        if ((int) $trx->user_id !== (int) $user->id && !$user->hasRole(['superadmin', 'admin', 'finance'])) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->presentTransaction($trx),
        ]);
    }

    /**
     * GET /api/v1/ppob/transactions/{trxCode}/receipt
     * Stream PDF e-struk untuk di-download.
     */
    public function downloadReceipt(string $trxCode, Request $request)
    {
        $trx  = PpobTransaction::where('trx_code', $trxCode)->firstOrFail();
        $user = $request->user();
        if ((int) $trx->user_id !== (int) $user->id && !$user->hasRole(['superadmin', 'admin', 'finance'])) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }
        if ($trx->status !== 'success') {
            return response()->json(['success' => false, 'message' => 'Struk tersedia untuk transaksi yang berhasil.'], 400);
        }
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.ppob-receipt', \App\Mail\PpobSuccessMail::payload($trx))
            ->setPaper('a4', 'portrait');
        return $pdf->download("E-Struk-{$trx->trx_code}.pdf");
    }

    /**
     * GET /api/v1/ppob/my-transactions
     */
    public function myTransactions(Request $request)
    {
        $query = PpobTransaction::with(['product:id,name', 'category:id,name'])
            ->forUser($request->user()->id)
            ->byStatus($request->query('status'))
            ->orderByDesc('created_at');

        $perPage = (int) ($request->query('limit', 20));
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(fn($t) => $this->presentTransaction($t));

        return response()->json(['success' => true, 'data' => $page]);
    }

    /* ──────────────────────────────────────────────────────────────────
     | Admin endpoints
     ────────────────────────────────────────────────────────────────── */

    public function adminIndex(Request $request)
    {
        $query = PpobTransaction::with(['user:id,name,email', 'product:id,name', 'category:id,name'])
            ->byStatus($request->query('status'))
            ->orderByDesc('created_at');

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('trx_code', 'like', "%{$search}%")
                  ->orWhere('ref1', 'like', "%{$search}%")
                  ->orWhere('customer_number', 'like', "%{$search}%");
            });
        }

        $perPage = (int) ($request->query('limit', 30));
        return response()->json(['success' => true, 'data' => $query->paginate($perPage)]);
    }

    public function adminRefund(Request $request, string $trxCode)
    {
        $request->validate(['notes' => 'nullable|string|max:500']);

        $trx = PpobTransaction::where('trx_code', $trxCode)->firstOrFail();
        try {
            $this->ppob->refund($trx, $request->user()->id, $request->input('notes'));
            return response()->json(['success' => true, 'message' => 'Refund berhasil.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Admin batalkan transaksi (hanya untuk yang belum dibayar).
     * POST /api/v1/admin/ppob/transactions/{trxCode}/cancel
     */
    public function adminCancel(Request $request, string $trxCode)
    {
        $request->validate(['notes' => 'nullable|string|max:500']);

        $trx = PpobTransaction::where('trx_code', $trxCode)->firstOrFail();
        try {
            $this->ppob->cancelTransaction($trx, $request->user()->id, $request->input('notes'));
            return response()->json(['success' => true, 'message' => 'Transaksi dibatalkan.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Admin verifikasi mutasi rekening masuk → eksekusi Rajabiller.
     * POST /api/v1/admin/ppob/transactions/{trxCode}/mark-paid
     */
    public function adminMarkPaid(Request $request, string $trxCode)
    {
        $request->validate(['notes' => 'nullable|string|max:500']);

        $trx = PpobTransaction::where('trx_code', $trxCode)->firstOrFail();
        try {
            $trx = $this->ppob->adminMarkPaidAndExecute(
                $trx,
                $request->user()->id,
                $request->input('notes'),
            );
            return response()->json([
                'success' => true,
                'message' => 'Pembayaran ditandai diterima. Eksekusi vendor sedang berjalan.',
                'data'    => $this->presentTransaction($trx),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Admin: saldo deposit Rajabiller terkini.
     * GET /api/v1/admin/ppob/balance
     *
     * Sumber: kolom `saldo_akhir_rajabiller` dari transaksi TERAKHIR yang punya
     * nilai saldo (direkam tiap transaksi sukses ke Rajabiller). Ini saldo deposit
     * setelah transaksi terakhir — paling akurat tanpa endpoint cek-saldo khusus.
     * (method info/cek/bayar Rajabiller tidak mengembalikan saldo di catalog call.)
     */
    public function adminBalance()
    {
        $latest = PpobTransaction::whereNotNull('saldo_akhir_rajabiller')
            ->where('saldo_akhir_rajabiller', '>', 0)
            ->orderByDesc('updated_at')
            ->first(['saldo_akhir_rajabiller', 'updated_at', 'trx_code']);

        return response()->json([
            'success'    => true,
            'balance'    => $latest ? (float) $latest->saldo_akhir_rajabiller : 0,
            'updated_at' => $latest?->updated_at,
            'trx_code'   => $latest?->trx_code,
            'source'     => 'last_transaction',
        ]);
    }

    /**
     * Admin: retry transaksi yang failed/pending (call ulang Rajabiller).
     * POST /api/v1/admin/ppob/transactions/{trxCode}/retry
     */
    public function adminRetry(string $trxCode)
    {
        $trx = PpobTransaction::where('trx_code', $trxCode)->firstOrFail();
        try {
            // Re-hit dengan ref1 BARU (failed/refundable/paid) — sekaligus fix retry
            // lama yang keliru panggil mark-paid (hanya berlaku status pending).
            $trx = $this->ppob->reHit($trx, request()->user()->id, 'Manual re-hit');
            return response()->json(['success' => true, 'data' => $this->presentTransaction($trx)]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function adminSyncCatalog(Request $request)
    {
        $group = $request->input('group');
        $exitCode = \Artisan::call('ppob:sync-catalog', array_filter(['--group' => $group]));
        return response()->json([
            'success' => $exitCode === 0,
            'message' => 'Sync command dispatched',
            'output'  => \Artisan::output(),
        ]);
    }

    public function adminCategories()
    {
        return response()->json([
            'success' => true,
            'data' => PpobCategory::orderBy('sort_order')->withCount('products')->get(),
        ]);
    }

    public function adminUpdateCategory(Request $request, int $id)
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'icon'          => 'sometimes|nullable|string|max:50',
            'color'         => 'sometimes|nullable|string|max:20',
            'markup_amount' => 'sometimes|numeric|min:0',
            'sort_order'    => 'sometimes|integer',
            'is_active'     => 'sometimes|boolean',
        ]);

        $cat = PpobCategory::findOrFail($id);
        $cat->update($data);

        return response()->json(['success' => true, 'data' => $cat->fresh()]);
    }

    /* ──────────────────────────────────────────────────────────────────
     | Presenter — format response transaksi untuk FE
     ────────────────────────────────────────────────────────────────── */
    private function presentTransaction(PpobTransaction $trx): array
    {
        return [
            'trx_code'        => $trx->trx_code,
            'ref1'            => $trx->ref1,
            'status'          => $trx->status,
            'rc'              => $trx->rc,
            'rc_message'      => RajaBillerService::userMessage($trx->rc),
            'product'         => [
                'id'   => $trx->product_id,
                'name' => $trx->product_name,
                'code' => $trx->product_code,
            ],
            'category'        => $trx->category?->only(['id', 'name', 'code', 'type']),
            'customer'        => [
                'number' => $trx->customer_number,
                'name'   => $trx->customer_name,
            ],
            'pricing'         => [
                'tagihan'      => (float) $trx->price_buy,
                'admin_fee'    => (float) $trx->admin_fee,
                'total_amount' => (float) $trx->total_amount,
            ],
            'serial_number'   => $trx->serial_number,
            'struk_url'       => $trx->struk_url,
            'template_struk'  => $trx->template_struk,
            'failure_reason'  => $trx->failure_reason,
            'timestamps'      => [
                'created_at'           => $trx->created_at,
                'inquired_at'          => $trx->inquired_at,
                'paid_at'              => $trx->paid_at,
                'completed_at'         => $trx->completed_at,
                'callback_received_at' => $trx->callback_received_at,
                'expires_at'           => $trx->expires_at,
            ],
        ];
    }
}
