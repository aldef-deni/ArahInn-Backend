<?php

namespace App\Http\Controllers;

use App\Models\Promo;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => Promo::latest()->get()]);
    }

    public function active()
    {
        return response()->json(['success' => true, 'data' => Promo::active()->get()]);
    }

    public function flashSales()
    {
        return response()->json([
            'success' => true,
            'data' => Promo::active()->where('type', 'flash_sale')->get(),
        ]);
    }

    public function validate(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'amount' => 'required|numeric',
        ]);
        $promo = Promo::where('code', $data['code'])->active()->first();

        if (!$promo) {
            return response()->json(['success' => false, 'message' => 'Kode promo tidak valid.'], 400);
        }
        if ($promo->quota && $promo->used_count >= $promo->quota) {
            return response()->json(['success' => false, 'message' => 'Kuota promo habis.'], 400);
        }
        if ($data['amount'] < $promo->min_purchase) {
            return response()->json(['success' => false, 'message' => 'Minimum pembelian tidak terpenuhi.'], 400);
        }

        $discount = $promo->calculateDiscount($data['amount']);

        return response()->json(['success' => true, 'data' => ['promo' => $promo, 'discount' => round($discount, 2)]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'code' => 'nullable|string|unique:promos',
            'type' => 'required|in:voucher,flash_sale,loyalty',
            'discount_type' => 'required|in:percent,fixed',
            'discount_value' => 'required|numeric|min:0',
            'min_purchase' => 'nullable|numeric',
            'max_discount' => 'nullable|numeric',
            'quota' => 'nullable|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ]);
        $data['min_purchase'] = $data['min_purchase'] ?? 0;
        $data['created_by']  = $request->user()->id;
        $promo = Promo::create($data);

        return response()->json(['success' => true, 'data' => $promo], 201);
    }

    public function update(Request $request, string $id)
    {
        $promo = Promo::findOrFail($id);
        $promo->update($request->only(['name', 'discount_type', 'discount_value', 'min_purchase', 'max_discount', 'quota', 'start_date', 'end_date', 'is_active']));

        return response()->json(['success' => true, 'data' => $promo]);
    }

    public function destroy(string $id)
    {
        Promo::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Promo dihapus.']);
    }
}
