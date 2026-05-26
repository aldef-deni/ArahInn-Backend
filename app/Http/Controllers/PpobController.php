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

    // ── Public catalog ────────────────────────────────────────────────

    public function categories()
    {
        $categories = PpobCategory::active()->ordered()->get();
        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function products(Request $request)
    {
        $query = PpobProduct::active()->with('category:id,code,name,group,type');

        if ($categoryCode = $request->query('category')) {
            $cat = PpobCategory::where('code', $categoryCode)->first();
            if (!$cat) return response()->json(['success' => true, 'data' => []]);
            $query->where('category_id', $cat->id);
        }
        if ($operator = $request->query('operator')) {
            $query->where('operator', $operator);
        }

        $products = $query->orderBy('nominal')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * Inquiry untuk pascabayar (cek tagihan dulu).
     */
    public function inquiry(Request $request)
    {
        $request->validate([
            'product_id'      => 'required|integer|exists:ppob_products,id',
            'customer_number' => 'required|string|max:50',
        ]);

        $product = PpobProduct::with('category')->find($request->product_id);
        if ($product->category->type !== 'pascabayar') {
            return response()->json([
                'success' => false,
                'message' => 'Inquiry hanya untuk produk pascabayar.',
            ], 422);
        }

        $result = $this->vendor->inquiry($product->raja_biller_code, $request->customer_number);
        return response()->json($result);
    }

    // ── Authenticated user actions ────────────────────────────────────

    public function store(Request $request)
    {
        $request->validate([
            'product_id'      => 'required|integer|exists:ppob_products,id',
            'customer_number' => 'required|string|max:50',
        ]);

        try {
            $result = $this->ppob->create($request->only(['product_id', 'customer_number']), $request->user()->id);
            return response()->json(['success' => true, 'data' => $result], 201);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function show(string $trxCode, Request $request)
    {
        $trx = PpobTransaction::with(['product:id,name', 'category:id,name', 'payment'])
            ->where('trx_code', $trxCode)
            ->firstOrFail();

        // Authorization: owner trx atau admin
        $user = $request->user();
        if ($trx->user_id !== $user->id && !$user->hasRole(['superadmin', 'admin', 'finance'])) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        return response()->json(['success' => true, 'data' => $trx]);
    }

    public function myTransactions(Request $request)
    {
        $query = PpobTransaction::with(['product:id,name', 'category:id,name'])
            ->forUser($request->user()->id)
            ->byStatus($request->query('status'))
            ->orderByDesc('created_at');

        $perPage = (int) ($request->query('limit', 20));
        return response()->json(['success' => true, 'data' => $query->paginate($perPage)]);
    }

    // ── Admin: monitoring, refund, manual retry ──────────────────────

    public function adminIndex(Request $request)
    {
        $query = PpobTransaction::with(['user:id,name,email', 'product:id,name', 'category:id,name'])
            ->byStatus($request->query('status'))
            ->orderByDesc('created_at');

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('trx_code', 'like', "%{$search}%")
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

    public function adminRetry(string $trxCode)
    {
        $trx = PpobTransaction::where('trx_code', $trxCode)->firstOrFail();
        if (!in_array($trx->status, ['failed', 'paid'])) {
            return response()->json(['success' => false, 'message' => "Status {$trx->status} tidak bisa di-retry."], 400);
        }
        $this->ppob->execute($trx);
        return response()->json(['success' => true, 'data' => $trx->fresh()]);
    }

    public function adminBalance()
    {
        return response()->json($this->vendor->getBalance());
    }

    // ── Admin: category CRUD ─────────────────────────────────────────

    public function adminCategories()
    {
        return response()->json([
            'success' => true,
            'data' => PpobCategory::ordered()->withCount('products')->get(),
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

        // Kalau markup berubah → recalculate price_sell semua produk di kategori ini
        if (isset($data['markup_amount'])) {
            $cat->products()->update([
                'price_sell' => \DB::raw('price_buy + ' . (float) $data['markup_amount']),
            ]);
        }

        return response()->json(['success' => true, 'data' => $cat->fresh()]);
    }
}
