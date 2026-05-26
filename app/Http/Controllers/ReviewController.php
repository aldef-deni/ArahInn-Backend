<?php
namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Hotel;
use App\Models\PropertyListing;
use App\Services\NotificationService;
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

    // ── Public: approved reviews for a property listing ───
    public function byProperty($propertyId)
    {
        $reviews = Review::with('user:id,name,avatar')
            ->where('property_id', $propertyId)
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

    // ── User: list ulasan yang dia kirim sendiri ──────────
    public function myReviews(Request $request)
    {
        $userId = Auth::id();

        $reviews = Review::with([
                'hotel:id,name,city,images',
                'property:id,title,city,images',
            ])
            ->where('user_id', $userId)
            ->latest()
            ->get()
            ->map(function ($r) {
                return [
                    'id'              => $r->id,
                    'rating'          => $r->rating,
                    'comment'         => $r->comment,
                    'status'          => $r->status,
                    'rejected_reason' => $r->rejected_reason,
                    'created_at'      => $r->created_at,
                    'target_type'     => $r->hotel_id ? 'hotel' : ($r->property_id ? 'property' : null),
                    'target_name'     => $r->hotel?->name ?? $r->property?->title ?? '-',
                    'target_city'     => $r->hotel?->city ?? $r->property?->city,
                    'target_image'    => $r->hotel?->images[0] ?? $r->property?->images[0] ?? null,
                    'target_id'       => $r->hotel_id ?? $r->property_id,
                ];
            });

        return response()->json(['success' => true, 'data' => $reviews]);
    }

    /**
     * Cek apakah user yang sedang login eligible untuk review hotel ini.
     * Eligible = pernah booking & check-out sudah lewat & belum pernah review booking yang sama.
     */
    public function eligibility(Request $request, $hotelId)
    {
        $userId = Auth::id();
        if (!$userId) {
            return response()->json([
                'success' => true,
                'data' => ['eligible' => false, 'reason' => 'must_login'],
            ]);
        }

        // Cari booking eligible: hotel ini, status paid+, sudah check-out
        $bookings = \App\Models\Booking::where('user_id', $userId)
            ->where('hotel_id', $hotelId)
            ->whereIn('status', ['paid', 'issued', 'rescheduled', 'completed'])
            ->whereDate('check_out', '<=', now())
            ->orderBy('check_out', 'desc')
            ->get(['id', 'booking_code', 'check_in', 'check_out', 'status']);

        if ($bookings->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => ['eligible' => false, 'reason' => 'no_completed_booking'],
            ]);
        }

        // Cek booking mana yang belum di-review
        $reviewedBookingIds = Review::where('user_id', $userId)
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->pluck('booking_id')
            ->toArray();

        $eligibleBooking = $bookings->whereNotIn('id', $reviewedBookingIds)->first();

        if (!$eligibleBooking) {
            return response()->json([
                'success' => true,
                'data' => ['eligible' => false, 'reason' => 'already_reviewed'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'eligible' => true,
                'booking_id'   => $eligibleBooking->id,
                'booking_code' => $eligibleBooking->booking_code,
                'check_out'    => $eligibleBooking->check_out,
            ],
        ]);
    }

    // ── User: submit review (hotel atau property) ─────────
    public function store(Request $request)
    {
        $data = $request->validate([
            'hotel_id'    => 'nullable|exists:hotels,id',
            'property_id' => 'nullable|exists:property_listings,id',
            'booking_id'  => 'nullable|exists:bookings,id',
            'rating'      => 'required|integer|min:1|max:5',
            'comment'     => 'required|string|min:10|max:1000',
        ]);

        if (empty($data['hotel_id']) && empty($data['property_id'])) {
            return response()->json([
                'message' => 'Target ulasan tidak valid (hotel atau properti wajib diisi).'
            ], 422);
        }

        $userId = Auth::id();

        // ── ATURAN BISNIS: review hotel hanya untuk customer yang pernah booking & sudah check-out ──
        if (!empty($data['hotel_id'])) {
            $bookingQuery = \App\Models\Booking::where('user_id', $userId)
                ->where('hotel_id', $data['hotel_id'])
                ->whereIn('status', ['paid', 'issued', 'rescheduled', 'completed'])
                ->whereDate('check_out', '<=', now()); // sudah lewat check-out

            // Kalau booking_id di-supply, pastikan booking itu valid untuk user ini
            if (!empty($data['booking_id'])) {
                $bookingQuery->where('id', $data['booking_id']);
            }

            $eligibleBooking = $bookingQuery->latest('check_out')->first();

            if (!$eligibleBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda hanya bisa memberi ulasan setelah booking selesai (lewat tanggal check-out).',
                ], 403);
            }

            // Auto-attach booking_id agar prevent duplicate per-booking jalan
            $data['booking_id'] = $eligibleBooking->id;
        }

        // Prevent duplicate review per booking
        if (!empty($data['booking_id'])) {
            $exists = Review::where('user_id', $userId)
                ->where('booking_id', $data['booking_id'])
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Anda sudah mengirim ulasan untuk pemesanan ini.'], 422);
            }
        }

        // Prevent duplicate review per property per user (selain booking-based)
        if (!empty($data['property_id'])) {
            $exists = Review::where('user_id', $userId)
                ->where('property_id', $data['property_id'])
                ->exists();
            if ($exists) {
                return response()->json(['message' => 'Anda sudah mengirim ulasan untuk properti ini.'], 422);
            }
        }

        $review = Review::create([
            'hotel_id'    => $data['hotel_id']    ?? null,
            'property_id' => $data['property_id'] ?? null,
            'booking_id'  => $data['booking_id']  ?? null,
            'rating'      => $data['rating'],
            'comment'     => $data['comment'],
            'user_id'     => Auth::id(),
            'status'      => 'pending',
        ]);

        // ── Notif admin: review baru perlu moderasi ─────────
        $targetType = $review->hotel_id ? 'hotel' : 'property';
        $targetName = $review->hotel_id
            ? (Hotel::find($review->hotel_id)?->name ?? '-')
            : (PropertyListing::find($review->property_id)?->title ?? '-');

        NotificationService::sendToRoles(
            ['superadmin', 'admin'],
            'review_pending',
            'Ulasan baru perlu moderasi',
            "Rating {$review->rating}★ untuk {$targetName} — menunggu persetujuan.",
            [
                'review_id'   => $review->id,
                'target_type' => $targetType,
                'target_id'   => $review->hotel_id ?? $review->property_id,
                'target_name' => $targetName,
                'rating'      => $review->rating,
            ]
        );

        // ── Notif owner: ada review baru di hotel/properti miliknya ─
        $ownerId = $review->hotel_id
            ? Hotel::find($review->hotel_id)?->owner_id
            : PropertyListing::find($review->property_id)?->owner_id;

        if ($ownerId) {
            NotificationService::send(
                $ownerId,
                'review_new',
                'Ulasan baru dari tamu',
                "Rating {$review->rating}★ untuk {$targetName}. Akan tampil publik setelah disetujui admin.",
                [
                    'review_id'   => $review->id,
                    'target_type' => $targetType,
                    'target_id'   => $review->hotel_id ?? $review->property_id,
                    'rating'      => $review->rating,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Ulasan berhasil dikirim dan menunggu persetujuan superadmin.',
            'data'    => $review,
        ], 201);
    }

    // ── Admin: all reviews ─────────────────────────────────
    public function adminIndex(Request $request)
    {
        $q = Review::with([
                'user:id,name,avatar,email',
                'hotel:id,name,city',
                'property:id,title,city',
            ])
            ->latest();

        if ($request->status && in_array($request->status, ['pending', 'approved', 'rejected'])) {
            $q->where('status', $request->status);
        }

        if ($request->type === 'hotel') {
            $q->whereNotNull('hotel_id');
        } elseif ($request->type === 'property') {
            $q->whereNotNull('property_id');
        }

        if ($request->search) {
            $search = '%' . $request->search . '%';
            $q->where(function ($sub) use ($search) {
                $sub->whereHas('user',     fn($u) => $u->where('name', 'like', $search))
                    ->orWhereHas('hotel',    fn($h) => $h->where('name', 'like', $search))
                    ->orWhereHas('property', fn($p) => $p->where('title', 'like', $search))
                    ->orWhere('comment', 'like', $search);
            });
        }

        $reviews = $q->paginate(20);

        // Tambahkan field "target" supaya frontend gampang render
        $reviews->getCollection()->transform(function ($r) {
            $r->target_type = $r->hotel_id ? 'hotel' : ($r->property_id ? 'property' : null);
            $r->target_name = $r->hotel?->name ?? $r->property?->title ?? '-';
            $r->target_city = $r->hotel?->city ?? $r->property?->city ?? null;
            return $r;
        });

        return response()->json([
            'success' => true,
            'data'    => $reviews,
        ]);
    }

    // ── Admin: approve ─────────────────────────────────────
    public function approve($id)
    {
        $review = Review::with(['hotel:id,name', 'property:id,title'])->findOrFail($id);
        $review->update(['status' => 'approved', 'rejected_reason' => null]);

        $targetName = $review->hotel?->name ?? $review->property?->title ?? '-';
        NotificationService::send(
            $review->user_id,
            'review_approved',
            'Ulasan Anda disetujui',
            "Ulasan Anda untuk {$targetName} sudah tampil publik. Terima kasih atas masukannya!",
            [
                'review_id'   => $review->id,
                'target_type' => $review->hotel_id ? 'hotel' : 'property',
                'target_id'   => $review->hotel_id ?? $review->property_id,
            ]
        );

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

        $review = Review::with(['hotel:id,name', 'property:id,title'])->findOrFail($id);
        $review->update([
            'status'          => 'rejected',
            'rejected_reason' => $data['reason'] ?? null,
        ]);

        $targetName = $review->hotel?->name ?? $review->property?->title ?? '-';
        $reasonText = $data['reason'] ?? 'Tidak memenuhi pedoman komunitas';
        NotificationService::send(
            $review->user_id,
            'review_rejected',
            'Ulasan Anda tidak dapat ditampilkan',
            "Ulasan untuk {$targetName} ditolak. Alasan: {$reasonText}",
            [
                'review_id'   => $review->id,
                'target_type' => $review->hotel_id ? 'hotel' : 'property',
                'target_id'   => $review->hotel_id ?? $review->property_id,
                'reason'      => $reasonText,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Ulasan ditolak.',
            'data'    => $review,
        ]);
    }
}
