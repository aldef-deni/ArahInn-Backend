<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\MarketManagerOwner;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $user  = $request->user();
        $query = Campaign::with('owner:id,name,email')->latest();

        // Market Manager: filter to assigned owners only
        if ($user->hasRole('admin')) {
            $ownerIds = MarketManagerOwner::where('market_manager_id', $user->id)->pluck('owner_id');
            if ($ownerIds->isEmpty()) {
                return response()->json(['success' => true, 'data' => []]);
            }
            $query->whereIn('owner_id', $ownerIds);
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    // ── Public: active campaigns for a specific hotel (via owner_id) ────────
    public function forHotel(string $hotelId)
    {
        $hotel = \App\Models\Hotel::findOrFail($hotelId);

        $campaigns = Campaign::where('status', 'active')
            ->where(function ($q) use ($hotel) {
                $q->whereNull('owner_id')
                  ->orWhere('owner_id', $hotel->owner_id);
            })
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Owner: campaigns targeting this owner (global + targeted) ────────
    public function myList(Request $request)
    {
        $userId = $request->user()->id;
        $campaigns = Campaign::with('owner:id,name,email')
            ->where('status', 'active')
            ->where(function ($q) use ($userId) {
                $q->whereNull('owner_id')
                  ->orWhere('owner_id', $userId);
            })
            ->latest()
            ->get();
        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'type'        => 'required|in:banner,email,push,popup',
            'target'      => 'required|in:all,new_user,loyal,inactive',
            'status'      => 'required|in:draft,active,inactive,ended',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'budget'      => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'owner_id'    => 'nullable|integer|exists:users,id',
        ]);

        $data['created_by'] = $request->user()->id;
        $data['budget']     = $data['budget'] ?? 0;

        $campaign = Campaign::create($data);
        return response()->json(['success' => true, 'data' => $campaign->load('owner:id,name,email')], 201);
    }

    public function update(Request $request, string $id)
    {
        $campaign = Campaign::findOrFail($id);

        $data = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'type'        => 'sometimes|required|in:banner,email,push,popup',
            'target'      => 'sometimes|required|in:all,new_user,loyal,inactive',
            'status'      => 'sometimes|required|in:draft,active,inactive,ended',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'budget'      => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'owner_id'    => 'nullable|integer|exists:users,id',
        ]);

        $campaign->update($data);
        return response()->json(['success' => true, 'data' => $campaign->load('owner:id,name,email')]);
    }

    public function destroy(string $id)
    {
        Campaign::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Campaign dihapus.']);
    }
}
