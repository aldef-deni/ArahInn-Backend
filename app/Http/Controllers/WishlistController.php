<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\SettingController;
use App\Models\Hotel;
use App\Models\PropertyListing;
use App\Models\WishlistItem;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    /** Konfigurasi wishlist (enabled, types, max) — dibaca FE utk tampilkan tombol & batas. */
    public function config()
    {
        return response()->json(['success' => true, 'data' => SettingController::wishlistConfig()]);
    }

    /** Daftar wishlist user + data kartu (hotel/properti) untuk ditampilkan. */
    public function index(Request $request)
    {
        $items = WishlistItem::where('user_id', $request->user()->id)->latest()->get();

        $hotelIds = $items->where('item_type', 'hotel')->pluck('item_id')->all();
        $propIds  = $items->where('item_type', 'property')->pluck('item_id')->all();

        $hotels = $hotelIds ? Hotel::whereIn('id', $hotelIds)->get()->keyBy('id') : collect();
        $props  = $propIds ? PropertyListing::whereIn('id', $propIds)->get()->keyBy('id') : collect();

        $firstImage = fn ($imgs) => (is_array($imgs) && count($imgs)) ? $imgs[0] : null;

        $data = $items->map(function ($it) use ($hotels, $props, $firstImage) {
            if ($it->item_type === 'hotel') {
                $h = $hotels->get($it->item_id);
                if (!$h) return null;
                return [
                    'wid' => $it->id, 'type' => 'hotel', 'id' => $h->id,
                    'name' => $h->name, 'slug' => $h->slug, 'city' => $h->city,
                    'image' => $firstImage($h->images), 'starRating' => $h->star_rating,
                    'savedAt' => $it->created_at,
                ];
            }
            $p = $props->get($it->item_id);
            if (!$p) return null;
            return [
                'wid' => $it->id, 'type' => 'property', 'id' => $p->id,
                'name' => $p->title, 'city' => $p->city, 'price' => $p->price,
                'image' => $firstImage($p->images), 'listingType' => $p->listing_type,
                'savedAt' => $it->created_at,
            ];
        })->filter()->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Daftar "type:id" item tersimpan — agar FE tahu status hati (saved). */
    public function ids(Request $request)
    {
        $keys = WishlistItem::where('user_id', $request->user()->id)
            ->get(['item_type', 'item_id'])
            ->map(fn ($i) => $i->item_type . ':' . $i->item_id)->all();

        return response()->json(['success' => true, 'data' => $keys]);
    }

    /** Tambah/hapus (toggle) item wishlist — hormati config superadmin. */
    public function toggle(Request $request)
    {
        $cfg = SettingController::wishlistConfig();
        if (!$cfg['enabled']) {
            return response()->json(['success' => false, 'message' => 'Fitur wishlist sedang dinonaktifkan.'], 403);
        }

        $v = $request->validate([
            'item_type' => 'required|in:hotel,property',
            'item_id'   => 'required|integer|min:1',
        ]);

        if (!in_array($v['item_type'], $cfg['types'], true)) {
            return response()->json(['success' => false, 'message' => 'Tipe item ini tidak diizinkan untuk wishlist.'], 422);
        }

        $uid = $request->user()->id;
        $existing = WishlistItem::where('user_id', $uid)
            ->where('item_type', $v['item_type'])->where('item_id', $v['item_id'])->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['success' => true, 'data' => ['saved' => false]]);
        }

        $max = (int) $cfg['max_items'];
        if ($max > 0 && WishlistItem::where('user_id', $uid)->count() >= $max) {
            return response()->json(['success' => false, 'message' => "Wishlist penuh (maksimal {$max} item). Hapus beberapa item dulu."], 422);
        }

        WishlistItem::create(['user_id' => $uid, 'item_type' => $v['item_type'], 'item_id' => $v['item_id']]);
        return response()->json(['success' => true, 'data' => ['saved' => true]]);
    }

    /** Hapus satu item wishlist (by wishlist id). */
    public function destroy(Request $request, string $id)
    {
        WishlistItem::where('id', $id)->where('user_id', $request->user()->id)->delete();
        return response()->json(['success' => true]);
    }
}
