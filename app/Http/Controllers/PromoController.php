<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    // ── Public: active global promos + owner-targeted promos for authenticated owner ──
    public function active(Request $request)
    {
        $query = Promo::active();
        $user  = auth('sanctum')->user();

        if ($user && $user->hasRole('owner')) {
            $userId = $user->id;
            $query->where(function ($q) use ($userId) {
                $q->whereNull('owner_id')
                  ->orWhere(function ($q2) use ($userId) {
                      $q2->where('owner_id', $userId)
                         ->where('created_by', '!=', $userId);
                  });
            });
        } else {
            $query->whereNull('owner_id');
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function flashSales()
    {
        $promos = Promo::active()->whereNull('owner_id')->where('type', 'flash_sale')->get();
        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Public: validate promo code at checkout ───────────────────────────
    public function validate(Request $request)
    {
        $data = $request->validate([
            'code'     => 'required|string',
            'amount'   => 'required|numeric',
            'hotel_id' => 'nullable|integer',
        ]);

        $promo = Promo::where('code', $data['code'])->active()->first();

        if (!$promo) {
            return response()->json(['success' => false, 'message' => 'Kode promo tidak valid.'], 400);
        }

        // Check owner scope
        if ($promo->owner_id !== null && isset($data['hotel_id'])) {
            $hotel = Hotel::find($data['hotel_id']);
            if (!$hotel || $hotel->owner_id !== $promo->owner_id) {
                return response()->json(['success' => false, 'message' => 'Kode promo tidak berlaku untuk hotel ini.'], 400);
            }
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

    // ── Admin: list all promos with owner info ────────────────────────────
    public function index()
    {
        $promos = Promo::with('owner:id,name,email')->latest()->get();
        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Owner: list own promos (created by owner themselves, not admin-targeted) ──
    public function myPromos(Request $request)
    {
        $userId = $request->user()->id;
        $promos = Promo::where('owner_id', $userId)
                       ->where('created_by', $userId)
                       ->latest()->get();
        return response()->json(['success' => true, 'data' => $promos]);
    }

    // ── Admin: list owners for dropdown ──────────────────────────────────
    public function ownersList()
    {
        $owners = User::role('owner')->select('id', 'name', 'email')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $owners]);
    }

    // ── Admin + Owner: create promo ───────────────────────────────────────
    public function store(Request $request)
    {
        $user  = $request->user();
        $isAdmin = $user->hasAnyRole(['admin', 'superadmin']);

        $data = $request->validate([
            'name'           => 'required|string',
            'code'           => 'nullable|string|unique:promos',
            'type'           => 'required|in:voucher,flash_sale,loyalty',
            'discount_type'  => 'required|in:percent,fixed',
            'discount_value' => 'required|numeric|min:0',
            'min_purchase'   => 'nullable|numeric',
            'max_discount'   => 'nullable|numeric',
            'quota'          => 'nullable|integer',
            'start_date'     => 'nullable|date',
            'end_date'       => 'nullable|date|after:start_date',
            'owner_id'       => 'nullable|integer|exists:users,id',
        ]);

        $data['min_purchase'] = $data['min_purchase'] ?? 0;
        $data['created_by']   = $user->id;

        if ($isAdmin) {
            // Admin: use owner_id from request (null = global)
            $data['owner_id'] = $data['owner_id'] ?? null;
        } else {
            // Owner: always scope to themselves
            $data['owner_id'] = $user->id;
        }

        $promo = Promo::create($data);
        return response()->json(['success' => true, 'data' => $promo->load('owner:id,name,email')], 201);
    }

    // ── Admin + Owner: update promo ───────────────────────────────────────
    public function update(Request $request, string $id)
    {
        $promo = Promo::findOrFail($id);
        $user  = $request->user();

        if (!$user->hasAnyRole(['admin', 'superadmin']) && $promo->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
        }

        $allowed = ['name', 'discount_type', 'discount_value', 'min_purchase',
                    'max_discount', 'quota', 'start_date', 'end_date', 'is_active'];

        // Admin also can update owner_id
        if ($user->hasAnyRole(['admin', 'superadmin'])) {
            $allowed[] = 'owner_id';
        }

        $promo->update($request->only($allowed));
        return response()->json(['success' => true, 'data' => $promo->load('owner:id,name,email')]);
    }

    // ── Admin + Owner: delete promo ───────────────────────────────────────
    public function destroy(Request $request, string $id)
    {
        $promo = Promo::findOrFail($id);
        $user  = $request->user();

        if (!$user->hasAnyRole(['admin', 'superadmin']) && $promo->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
        }

        $promo->delete();
        return response()->json(['success' => true, 'message' => 'Promo dihapus.']);
    }
}
