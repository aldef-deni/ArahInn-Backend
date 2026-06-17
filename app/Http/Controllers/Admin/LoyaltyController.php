<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(private LoyaltyService $loyalty) {}

    /** Konfigurasi loyalitas (rate, threshold, multiplier, dll). */
    public function getConfig()
    {
        return response()->json(['success' => true, 'data' => $this->loyalty->config()]);
    }

    public function setConfig(Request $request)
    {
        $data = $request->validate([
            'enabled'           => 'sometimes|boolean',
            'earn_per'          => 'sometimes|integer|min:1|max:1000000',
            'activation_points' => 'sometimes|integer|min:0|max:1000000',
            'tier_silver'       => 'sometimes|integer|min:1',
            'tier_gold'         => 'sometimes|integer|min:1',
            'tier_platinum'     => 'sometimes|integer|min:1',
            'mult_member'       => 'sometimes|integer|min:1|max:100',
            'mult_silver'       => 'sometimes|integer|min:1|max:100',
            'mult_gold'         => 'sometimes|integer|min:1|max:100',
            'mult_platinum'     => 'sometimes|integer|min:1|max:100',
        ]);

        $cfg = $this->loyalty->saveConfig($data);
        return response()->json(['success' => true, 'data' => $cfg, 'message' => 'Konfigurasi loyalitas disimpan.']);
    }

    /** Daftar member + saldo, lifetime, tier (search + paginate). */
    public function users(Request $request)
    {
        $q = User::query()->select('id', 'name', 'email', 'avatar', 'loyalty_tier_override', 'created_at');

        if ($s = trim((string) $request->q)) {
            $q->where(fn($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }

        $users = $q->orderByDesc('id')->paginate($request->per_page ?? 20);

        $data = collect($users->items())->map(function ($u) {
            $lifetime = $this->loyalty->getLifetimeEarned($u->id);
            return [
                'id'             => $u->id,
                'name'           => $u->name,
                'email'          => $u->email,
                'avatar'         => $u->avatar,
                'balance'        => $this->loyalty->getBalance($u->id),
                'lifetime'       => $lifetime,
                'tier'           => $this->loyalty->getTier($u->id),
                'tier_override'  => $u->loyalty_tier_override,
                'created_at'     => $u->created_at,
            ];
        });

        return response()->json([
            'success'    => true,
            'data'       => $data,
            'pagination' => ['total' => $users->total(), 'page' => $users->currentPage(), 'per_page' => $users->perPage()],
        ]);
    }

    /** Penyesuaian poin manual (+/-). */
    public function adjust(Request $request, int $id)
    {
        $data = $request->validate([
            'points' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:255',
        ]);

        User::findOrFail($id);
        $this->loyalty->adjust($id, (int) $data['points'], $data['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Poin disesuaikan.',
            'data'    => ['balance' => $this->loyalty->getBalance($id), 'tier' => $this->loyalty->getTier($id)],
        ]);
    }

    /** Set / hapus override tier (penurunan/penaikan manual oleh superadmin). */
    public function setTier(Request $request, int $id)
    {
        $data = $request->validate([
            'tier' => 'nullable|in:member,silver,gold,platinum',
        ]);

        $user = User::findOrFail($id);
        $user->update(['loyalty_tier_override' => $data['tier'] ?? null]);

        return response()->json([
            'success' => true,
            'message' => $data['tier'] ? "Tier dikunci ke {$data['tier']}." : 'Override tier dihapus (kembali otomatis).',
            'data'    => ['tier' => $this->loyalty->getTier($id), 'tier_override' => $user->loyalty_tier_override],
        ]);
    }
}
