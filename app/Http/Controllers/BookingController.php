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
            'points_to_redeem' => 'nullable|integer|min:0',
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
            'points_to_redeem' => 'nullable|integer|min:0',
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
        // Terima id numeric ATAU booking_code (URL ramah, mis. /orders/ARH123456)
        $query = Booking::with(['hotel', 'room', 'payments', 'user:id,name,email']);
        $booking = is_numeric($id)
            ? $query->find($id)
            : $query->where('booking_code', $id)->first();

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
        }

        $user = $request->user();
        $role = $user->getRoleNames()->first();
        $isAdmin       = in_array($role, ['superadmin', 'admin', 'finance']);
        $isCustomerOwn = (int) $booking->user_id === (int) $user->id;
        // Owner boleh lihat booking untuk hotel miliknya (extranet My ArahInn).
        $isHotelOwner  = $role === 'owner' && (int) ($booking->hotel->owner_id ?? 0) === (int) $user->id;

        if (!$isAdmin && !$isCustomerOwn && !$isHotelOwner) {
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

        $wasPaid = in_array($booking->status, ['paid', 'issued', 'rescheduled']);

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

        // Kalau booking sebelumnya sudah dibayar → ini permintaan refund. Notif ke finance/admin.
        if ($wasPaid) {
            NotificationService::sendToRoles(
                ['superadmin', 'admin', 'finance'],
                'booking_refund_request',
                'Permintaan refund baru',
                "Booking #{$booking->booking_code} ({$booking->guest_name}) butuh refund Rp " . number_format($booking->total_price, 0, ',', '.') . ".",
                [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'amount'       => (float) $booking->total_price,
                    'guest_name'   => $booking->guest_name,
                ]
            );
        }

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

    /**
     * Kirim ulang e-voucher booking ke email tamu.
     * Customer (pemilik booking) atau admin/superadmin/finance bisa pakai endpoint ini.
     */
    public function resendVoucher(Request $request, string $id)
    {
        $booking = Booking::with('hotel')->findOrFail($id);
        $user = $request->user();

        $isAdmin = in_array($user->getRoleNames()->first(), ['superadmin', 'admin', 'finance']);
        if (!$isAdmin && (int) $booking->user_id !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }
        if (!in_array($booking->status, ['paid', 'issued', 'rescheduled'])) {
            return response()->json(['success' => false, 'message' => 'Booking belum dibayar, voucher belum bisa dikirim.'], 400);
        }

        try {
            \Illuminate\Support\Facades\Mail::to($booking->guest_email)
                ->send(new \App\Mail\BookingIssuedMail($booking));
        } catch (\Throwable $e) {
            $booking->update(['voucher_error' => mb_substr($e->getMessage(), 0, 480)]);
            logger()->error('ResendVoucher failed', [
                'booking_code' => $booking->booking_code,
                'error'        => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim voucher. ' . $e->getMessage(),
            ], 500);
        }

        // Berhasil → bersihkan penanda gagal
        $booking->update(['voucher_sent_at' => now(), 'voucher_error' => null]);

        return response()->json([
            'success' => true,
            'message' => "Voucher telah dikirim ulang ke {$booking->guest_email}.",
        ]);
    }

    /**
     * Stream e-voucher PDF langsung ke client (untuk download).
     * Bisa diakses customer (pemilik booking), owner hotel, atau admin.
     */
    public function downloadVoucher(Request $request, string $id)
    {
        $booking = Booking::with(['hotel:id,name,owner_id', 'room:id,name'])->findOrFail($id);
        $user    = $request->user();

        // Otorisasi: admin/superadmin/finance, customer pemilik booking, atau owner hotel
        $roles      = $user->getRoleNames();
        $isAdmin    = $roles->contains(fn($r) => in_array($r, ['superadmin','admin','finance']));
        $isCustomer = (int) $booking->user_id === (int) $user->id;
        $isOwner    = $roles->contains('owner') && (int) $booking->hotel?->owner_id === (int) $user->id;

        if (!$isAdmin && !$isCustomer && !$isOwner) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        if (!in_array($booking->status, ['paid', 'issued', 'rescheduled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher hanya tersedia untuk booking yang sudah dibayar.',
            ], 400);
        }

        $booking->load(['hotel', 'room']);
        $payload = [
            'booking'     => $booking,
            'hotel'       => $booking->hotel,
            'room'        => $booking->room,
            'checkIn'     => $booking->check_in->translatedFormat('D, d M Y'),
            'checkOut'    => $booking->check_out->translatedFormat('D, d M Y'),
            'nights'      => $booking->total_nights,
            'totalPrice'  => number_format($booking->total_price, 0, ',', '.'),
            'basePrice'   => number_format($booking->base_price, 0, ',', '.'),
            'markupAmt'   => number_format($booking->markup_amount, 0, ',', '.'),
            'taxAmt'      => number_format($booking->tax_amount, 0, ',', '.'),
            'promoDisc'   => number_format($booking->promo_discount ?? 0, 0, ',', '.'),
            'loyaltyDisc' => number_format($booking->loyalty_discount ?? 0, 0, ',', '.'),
            'priceSuffix' => (int) ($booking->price_suffix ?? 0),
            'appUrl'      => rtrim(config('app.url'), '/'),
            'frontendUrl' => rtrim(config('app.frontend_url'), '/'),
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.booking-voucher', $payload)
            ->setPaper('a4', 'portrait');

        return $pdf->download("E-Voucher-{$booking->booking_code}.pdf");
    }

    public function refund(Request $request, string $id)
    {
        $booking = Booking::with('hotel:id,name,owner_id')->findOrFail($id);
        $booking->update(['status' => 'refunded']);
        ActivityLogService::log($request->user()->id, 'REFUND_BOOKING', 'booking', $id, $request);

        // Notif: customer dapat info refund disetujui & ditransfer
        NotificationService::send(
            $booking->user_id,
            'booking_refunded',
            'Refund Anda telah diproses',
            "Dana booking #{$booking->booking_code} sebesar Rp " . number_format($booking->total_price, 0, ',', '.') . " telah dikembalikan.",
            [
                'booking_id'   => $booking->id,
                'booking_code' => $booking->booking_code,
                'amount'       => (float) $booking->total_price,
            ]
        );

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
