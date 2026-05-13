<?php
namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    // ── Public: approved reviews for a hotel ──────────────
    public function byHotel($hotelId)
    {
        $reviews = Review::with('user:id,name,avatar')
            ->where('hotel_id', $hotelId)
            ->approved()
            ->latest()
            ->get()
            ->map(fn($r) => [
                'id'         => $r->id,
                'rating'     => $r->rating,
                'comment'    => $r->comment,
                'created_at' => $r->created_at,
                'user'       => [
                    'name'   => $r->user?->name ?? 'Tamu',
                    'avatar' => $r->user?->avatar,
                ],
            ]);

        $avg = $reviews->avg('rating');

        return response()->json([
            'success' => true,
            'data'    => [
                'reviews'        => $reviews,
                'average_rating' => $avg ? round($avg, 1) : null,
                'total'          => $reviews->count(),
            ],
        ]);
    }

    // ── User: submit review ────────────────────────────────
    public function store(Request $request)
    {
        $data = $request->validate([
            'hotel_id'   => 'required|exists:hotels,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'required|string|min:10|max:1000',
        ]);

        // Prevent duplicate review per booking
        if (!empty($data['booking_id'])) {
            $exists = Review::where('user_id', Auth::id())
                ->where('booking_id', $data['booking_id'])
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Anda sudah mengirim ulasan untuk pemesanan ini.'], 422);
            }
        }

        $review = Review::create([
            ...$data,
            'user_id' => Auth::id(),
            'status'  => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ulasan berhasil dikirim dan menunggu persetujuan.',
            'data'    => $review,
        ], 201);
    }

    // ── Admin: all reviews ─────────────────────────────────
    public function adminIndex(Request $request)
    {
        $q = Review::with(['user:id,name,avatar,email', 'hotel:id,name,city'])
            ->latest();

        if ($request->status && in_array($request->status, ['pending', 'approved', 'rejected'])) {
            $q->where('status', $request->status);
        }

        if ($request->search) {
            $search = '%' . $request->search . '%';
            $q->where(function ($sub) use ($search) {
                $sub->whereHas('user',  fn($u) => $u->where('name', 'like', $search))
                    ->orWhereHas('hotel', fn($h) => $h->where('name', 'like', $search))
                    ->orWhere('comment', 'like', $search);
            });
        }

        $reviews = $q->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $reviews,
        ]);
    }

    // ── Admin: approve ─────────────────────────────────────
    public function approve($id)
    {
        $review = Review::findOrFail($id);
        $review->update(['status' => 'approved', 'rejected_reason' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Ulasan berhasil disetujui.',
            'data'    => $review,
        ]);
    }

    // ── Admin: reject ──────────────────────────────────────
    public function reject(Request $request, $id)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $review = Review::findOrFail($id);
        $review->update([
            'status'          => 'rejected',
            'rejected_reason' => $data['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ulasan ditolak.',
            'data'    => $review,
        ]);
    }
}
