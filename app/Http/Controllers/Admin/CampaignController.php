<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    // Admin: semua campaign (global) + jumlah owner yang mengikuti
    public function index(Request $request)
    {
        $campaigns = Campaign::withCount('followers')->latest()->get();
        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Public: campaigns aktif untuk hotel ini = campaign yang DIIKUTI ownernya ──
    public function forHotel(string $hotelId)
    {
        $hotel = \App\Models\Hotel::findOrFail($hotelId);

        $campaigns = Campaign::where('status', 'active')
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->whereHas('followers', fn($q) => $q->where('users.id', $hotel->owner_id))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Public: campaign aktif untuk ditampilkan di home website ──────────
    // Semua campaign dibuat superadmin = global. Tampilkan yang status='active'
    // & belum expired (termasuk upcoming).
    public function activePublic()
    {
        $campaigns = Campaign::where('status', 'active')
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Owner: SEMUA campaign aktif (global) + flag followed untuk owner ini ──
    public function myList(Request $request)
    {
        $userId = $request->user()->id;
        $campaigns = Campaign::where('status', 'active')
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
            ->withCount(['followers as is_followed' => fn($q) => $q->where('users.id', $userId)])
            ->latest()
            ->get()
            ->map(function ($c) {
                $c->followed = (bool) $c->is_followed;
                unset($c->is_followed);
                return $c;
            });
        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    // ── Owner: ikut / berhenti ikut campaign ─────────────────────────────
    public function follow(Request $request, string $id)
    {
        $campaign = Campaign::where('status', 'active')->findOrFail($id);
        $campaign->followers()->syncWithoutDetaching([$request->user()->id]);

        return response()->json([
            'success'  => true,
            'message'  => 'Campaign diikuti. Akan tampil di halaman properti Anda.',
            'followed' => true,
        ]);
    }

    public function unfollow(Request $request, string $id)
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->followers()->detach($request->user()->id);

        return response()->json([
            'success'  => true,
            'message'  => 'Campaign dihentikan.',
            'followed' => false,
        ]);
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
        ]);

        // Semua campaign = global (dibuat superadmin, masuk ke semua owner).
        $data['owner_id']   = null;
        $data['created_by'] = $request->user()->id;
        $data['budget']     = $data['budget'] ?? 0;

        $campaign = Campaign::create($data);
        return response()->json(['success' => true, 'data' => $campaign], 201);
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
        ]);

        $campaign->update($data);
        return response()->json(['success' => true, 'data' => $campaign]);
    }

    public function destroy(string $id)
    {
        Campaign::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Campaign dihapus.']);
    }
}
