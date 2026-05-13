<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketManagerOwner;
use App\Models\User;
use Illuminate\Http\Request;


class MarketManagerController extends Controller
{
    // ── Superadmin: list all Market Managers ─────────────────────────────
    public function listMMs()
    {
        $mms = User::role('admin')
            ->select('id', 'name', 'email', 'phone', 'avatar')
            ->orderBy('name')
            ->get()
            ->map(function ($mm) {
                $mm->assigned_count = MarketManagerOwner::where('market_manager_id', $mm->id)->count();
                return $mm;
            });

        return response()->json(['success' => true, 'data' => $mms]);
    }

    // ── Superadmin: get owners assigned to a specific MM ──────────────────
    public function getMMOwners(string $mmId)
    {
        $ownerIds = MarketManagerOwner::where('market_manager_id', $mmId)->pluck('owner_id');
        $owners   = User::whereIn('id', $ownerIds)->select('id', 'name', 'email')->get();

        return response()->json(['success' => true, 'data' => $owners, 'owner_ids' => $ownerIds]);
    }

    // ── Superadmin: set owners for a specific MM ──────────────────────────
    public function setMMOwners(Request $request, string $mmId)
    {
        $data = $request->validate([
            'owner_ids'   => 'required|array',
            'owner_ids.*' => 'integer|exists:users,id',
        ]);

        MarketManagerOwner::where('market_manager_id', $mmId)->delete();

        if (!empty($data['owner_ids'])) {
            $rows = array_map(fn($id) => [
                'market_manager_id' => (int) $mmId,
                'owner_id'          => $id,
            ], $data['owner_ids']);
            MarketManagerOwner::insert($rows);
        }

        return response()->json(['success' => true, 'message' => 'Owner berhasil diupdate.']);
    }

    // ── Get owners assigned to current Market Manager ─────────────────────
    public function getAssignedOwners(Request $request)
    {
        $mmId     = $request->user()->id;
        $ownerIds = MarketManagerOwner::where('market_manager_id', $mmId)->pluck('owner_id');
        $owners   = User::whereIn('id', $ownerIds)->select('id', 'name', 'email')->get();

        return response()->json(['success' => true, 'data' => $owners, 'owner_ids' => $ownerIds]);
    }

    // ── Owner: get the Market Manager assigned to me ──────────────────────
    public function myMarketManager(Request $request)
    {
        $ownerId  = $request->user()->id;
        $relation = MarketManagerOwner::where('owner_id', $ownerId)->first();

        if (!$relation) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $mm = User::select('id', 'name', 'email', 'phone', 'avatar')->find($relation->market_manager_id);
        return response()->json(['success' => true, 'data' => $mm]);
    }

    // ── Set (replace all) owners assigned to current Market Manager ───────
    public function setAssignedOwners(Request $request)
    {
        $data = $request->validate([
            'owner_ids'   => 'required|array',
            'owner_ids.*' => 'integer|exists:users,id',
        ]);

        $mmId = $request->user()->id;

        MarketManagerOwner::where('market_manager_id', $mmId)->delete();

        if (!empty($data['owner_ids'])) {
            $rows = array_map(fn($id) => [
                'market_manager_id' => $mmId,
                'owner_id'          => $id,
            ], $data['owner_ids']);
            MarketManagerOwner::insert($rows);
        }

        return response()->json(['success' => true, 'message' => 'Handling akomodasi diperbarui.']);
    }
}
