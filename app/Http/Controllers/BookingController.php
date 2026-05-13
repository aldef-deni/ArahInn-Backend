<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Hotel;
use App\Services\ActivityLogService;
use App\Services\BookingService;
use App\Services\NotificationService;
use App\Services\PricingService;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private PricingService $pricing,
        private BookingService $booking,
    ) {}

    public function calculatePrice(Request $request)
    {
        $data = $request->validate([
            'room_id'    => 'required|integer|exists:rooms,id',
            'check_in'   => 'required|date',
            'check_out'  => 'required|date|after:check_in',
            'promo_code' => 'nullable|string',
            'use_points' => 'boolean',
            'room_count' => 'nullable|integer|min:1',
        ]);

        try {
            $result = $this->pricing->calculate(array_merge($data, ['user_id' => $request->user()->id]));
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id'    => 'required|integer|exists:rooms,id',
            'hotel_id'   => 'required|integer|exists:hotels,id',
            'check_in'   => 'required|date',
            'check_out'  => 'required|date|after:check_in',
            'guests'     => 'required|integer|min:1',
            'room_count' => 'nullable|integer|min:1',
            'guest_name' => 'required|string',
            'guest_email' => 'required|email',
            'guest_phone' => 'nullable|string',
            'promo_code' => 'nullable|string',
            'use_points' => 'boolean',
            'notes'      => 'nullable|string',
        ]);

        try {
            $result = $this->booking->create($data, $request->user()->id);
            $booking = $result['booking'];
            ActivityLogService::log($request->user()->id, 'CREATE_BOOKING', 'booking', $booking->id, $request);

            $ownerId = $booking->hotel?->owner_id;
            if ($ownerId) {
                NotificationService::send(
                    $ownerId, 'booking_new',
                    'Pemesanan Baru',
                    "Tamu {$booking->guest_name} memesan kamar di {$booking->hotel->name}.",
                    ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]
                );
            }
            NotificationService::sendToRoles(['superadmin', 'admin'], 'booking_new',
                'Pemesanan Baru',
                "Booking #{$booking->booking_code} dari {$booking->guest_name}.",
                ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]
            );

            return response()->json(['success' => true, 'message' => 'Booking berhasil.', 'data' => $result], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function myOrders(Request $request)
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->with(['hotel:id,name,city,images', 'room:id,name,type', 'payments:id,booking_id,status,method,paid_at'])
            ->byStatus($request->status)
            ->orderBy('created_at', 'desc')
            ->paginate($request->limit ?? 8);

        return response()->json([
            'success' => true,
            'data' => $bookings->items(),
            'pagination' => ['total' => $bookings->total(), 'page' => $bookings->currentPage()],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $booking = Booking::with(['hotel', 'room', 'payments', 'user:id,name,email'])->findOrFail($id);
        $user = $request->user();

        $adminRoles = ['superadmin', 'admin', 'finance'];
        if (!in_array($user->getRoleNames()->first(), $adminRoles) && (int) $booking->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        return response()->json(['success' => true, 'data' => $booking]);
    }

    public function cancel(Request $request, string $id)
    {
        $booking = Booking::findOrFail($id);
        $user = $request->user();

        if (!in_array($user->getRoleNames()->first(), ['superadmin', 'admin']) && (int) $booking->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }
        if (in_array($booking->status, ['canceled', 'refunded'])) {
            return response()->json(['success' => false, 'message' => 'Booking sudah dibatalkan.'], 400);
        }

        $this->booking->cancel($booking);
        ActivityLogService::log($user->id, 'CANCEL_BOOKING', 'booking', $id, $request);

        $ownerId = $booking->hotel?->owner_id;
        if ($ownerId) {
            NotificationService::send(
                $ownerId, 'booking_canceled',
                'Pemesanan Dibatalkan',
                "Booking #{$booking->booking_code} dari {$booking->guest_name} telah dibatalkan.",
                ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]
            );
        }
        NotificationService::send(
            $booking->user_id, 'booking_canceled',
            'Pemesanan Dibatalkan',
            "Booking #{$booking->booking_code} Anda telah dibatalkan.",
            ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]
        );

        return response()->json(['success' => true, 'message' => 'Booking dibatalkan.', 'data' => $booking->fresh()]);
    }

    public function reschedule(Request $request, string $id)
    {
        $data = $request->validate([
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $booking = Booking::with(['hotel', 'room'])->findOrFail($id);
        $user    = $request->user();
        $isAdmin = in_array($user->getRoleNames()->first(), ['superadmin', 'admin']);

        if (!$isAdmin && (int) $booking->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }
        if (!in_array($booking->status, ['paid', 'issued'])) {
            return response()->json(['success' => false, 'message' => 'Booking tidak dapat dijadwal ulang.'], 400);
        }

        $oldCheckIn  = $booking->check_in->format('d M Y');
        $oldCheckOut = $booking->check_out->format('d M Y');

        $booking->update([
            'check_in'  => $data['check_in'],
            'check_out' => $data['check_out'],
            'status'    => 'rescheduled',
        ]);
        $booking->refresh();

        try {
            \Illuminate\Support\Facades\Mail::to($booking->guest_email)
                ->send(new \App\Mail\BookingRescheduledMail($booking, $oldCheckIn, $oldCheckOut, 'guest'));
        } catch (\Throwable) {}

        try {
            $ownerEmail = \App\Models\User::find($booking->hotel?->owner_id)?->email;
            if ($ownerEmail) {
                \Illuminate\Support\Facades\Mail::to($ownerEmail)
                    ->send(new \App\Mail\BookingRescheduledMail($booking, $oldCheckIn, $oldCheckOut, 'owner'));
            }
        } catch (\Throwable) {}

        NotificationService::send(
            $booking->user_id, 'booking_rescheduled',
            'Jadwal Booking Diubah',
            "Booking #{$booking->booking_code} telah dijadwal ulang ke {$booking->check_in->format('d M Y')}.",
            ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code]
        );

        return response()->json(['success' => true, 'data' => $booking]);
    }

    public function refund(Request $request, string $id)
    {
        $booking = Booking::findOrFail($id);
        $booking->update(['status' => 'refunded']);
        ActivityLogService::log($request->user()->id, 'REFUND_BOOKING', 'booking', $id, $request);

        return response()->json(['success' => true, 'message' => 'Refund diproses.', 'data' => $booking]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Booking::with(['user:id,name,email', 'hotel:id,name,city', 'room:id,name,type']);

        if ($user->hasRole('owner')) {
            $hotelIds = Hotel::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('hotel_id', $hotelIds);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->hotel_id) {
            $query->where('hotel_id', $request->hotel_id);
        }
        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $result = $query->orderBy('created_at', 'desc')->paginate($request->limit ?? 20);

        return response()->json([
            'success' => true,
            'data' => $result->items(),
            'pagination' => [
                'total' => $result->total(),
                'page' => $result->currentPage(),
                'total_pages' => $result->lastPage(),
            ],
        ]);
    }
}
