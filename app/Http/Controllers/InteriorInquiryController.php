<?php

namespace App\Http\Controllers;

use App\Models\InteriorInquiry;
use Illuminate\Http\Request;

class InteriorInquiryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'nama'              => 'required|string|max:255',
            'no_hp'             => 'required|string|max:20',
            'proyek'            => 'required|string|max:255',
            'desain_referensi'  => 'nullable|string|max:255',
        ]);

        $data['user_id'] = $request->user()?->id;

        $inquiry = InteriorInquiry::create($data);

        return response()->json([
            'message' => 'Inquiry berhasil disimpan.',
            'data'    => $inquiry,
        ], 201);
    }

    public function index(Request $request)
    {
        $inquiries = InteriorInquiry::with('user')
            ->latest()
            ->paginate(20);

        return response()->json(['data' => $inquiries]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:new,contacted,closed',
        ]);

        $inquiry = InteriorInquiry::findOrFail($id);
        $inquiry->update(['status' => $request->status]);

        return response()->json(['message' => 'Status diperbarui.', 'data' => $inquiry]);
    }
}
