<?php

namespace App\Http\Controllers;

use App\Models\TravelBooking;
use App\Services\TravelService;
use App\Http\Controllers\Admin\SettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Alur pesanan tiket travel:
 *  1. checkout()  → book ke Rajabiller (buat PNR) + simpan travel_booking (pending_payment)
 *  2. pay()       → setelah customer bayar ArahInn → issue ke Rajabiller (potong deposit/simulate)
 *                   → simpan url e-tiket → status issued
 *
 * Catatan money flow: customer bayar TOTAL (harga vendor + markup) ke ArahInn.
 * ArahInn issue tiket (potong saldo deposit Rajabiller = harga vendor). Markup = margin ArahInn.
 */
class TravelBookingController extends Controller
{
    public function __construct(private TravelService $travel) {}

    /* ── CHECKOUT (book) ────────────────────────────────────────────── */

    public function checkout(Request $request)
    {
        $moda = $request->input('moda');
        return match ($moda) {
            'kereta'  => $this->checkoutKereta($request),
            'pesawat' => $this->checkoutPesawat($request),
            'pelni'   => $this->checkoutPelni($request),
            default   => response()->json(['success' => false, 'message' => 'Moda tidak valid.'], 422),
        };
    }

    private function checkoutKereta(Request $request)
    {
        $v = $request->validate([
            'origin'            => 'required|string',
            'destination'       => 'required|string',
            'date'              => 'required|date_format:Y-m-d',
            'train_number'      => 'required|string',
            'grade'             => 'required|string',
            'class'             => 'required|string',
            'adult'             => 'required|integer|min:1|max:7',
            'infant'            => 'nullable|integer|min:0|max:4',
            'price_adult'       => 'required|numeric',
            'markup'            => 'nullable|integer|min:0',
            'train_name'        => 'required|string',
            'departure_station' => 'required|string',
            'departure_time'    => 'required|string',
            'arrival_station'   => 'required|string',
            'arrival_time'      => 'required|string',
            'passengers.adults'             => 'required|array|min:1',
            'passengers.adults.*.name'      => 'required|string',
            'passengers.adults.*.birthdate' => 'required|date_format:Y-m-d',
            'passengers.adults.*.phone'     => 'required|string',
            'passengers.adults.*.id_number' => 'required|string',
            'passengers.infants'            => 'nullable|array',
        ]);

        $adult  = (int) $v['adult'];
        $infant = (int) ($v['infant'] ?? 0);
        $markup = (int) ($v['markup'] ?? SettingController::travelMarkup()['amount']);

        $res = $this->travel->bookTrain([
            'origin'           => strtoupper($v['origin']),
            'destination'      => strtoupper($v['destination']),
            'date'             => $v['date'],
            'trainNumber'      => $v['train_number'],
            'grade'            => $v['grade'],
            'class'            => $v['class'],
            'adult'            => $adult,
            'child'            => 0,
            'infant'           => $infant,
            'priceAdult'       => $v['price_adult'],
            'priceChild'       => '-',
            'priceInfant'      => '-',
            'trainName'        => $v['train_name'],
            'departureStation' => $v['departure_station'],
            'departureTime'    => $v['departure_time'],
            'arrivalStation'   => $v['arrival_station'],
            'arrivalTime'      => $v['arrival_time'],
            'passengers'       => [
                'adults'  => array_map(fn($p) => [
                    'name' => $p['name'], 'birthdate' => $p['birthdate'],
                    'phone' => $p['phone'], 'idNumber' => $p['id_number'],
                ], $v['passengers']['adults']),
                'infants' => array_map(fn($p) => [
                    'name' => $p['name'] ?? '', 'birthdate' => $p['birthdate'] ?? '',
                    'idNumber' => $p['id_number'] ?? '',
                ], $v['passengers']['infants'] ?? []),
            ],
        ]);

        if (!TravelService::isSuccess($res['rc'] ?? null)) {
            return response()->json(['success' => false, 'message' => $res['rd'] ?? TravelService::userMessage($res['rc'] ?? null)], 422);
        }

        $d = $res['data'] ?? [];
        $vendorPrice = (int) round(((float) $v['price_adult']) * $adult);

        $booking = $this->createRecord($request, [
            'moda'         => 'kereta',
            'product_code' => TravelService::PRODUCT_KERETA,
            'origin'       => strtoupper($v['origin']),
            'destination'  => strtoupper($v['destination']),
            'origin_name'  => $v['departure_station'],
            'destination_name' => $v['arrival_station'],
            'depart_date'  => $v['date'],
            'depart_time'  => $v['departure_time'],
            'arrive_time'  => $v['arrival_time'],
            'service_name' => $v['train_name'],
            'class'        => $v['class'],
            'pax'          => $adult,
            'vendor_price' => $vendorPrice,
            'markup'       => $markup,
            'total_price'  => $vendorPrice + $markup * $adult,
            'vendor_booking_code'   => $d['bookingCode'] ?? null,
            'vendor_transaction_id' => $d['transactionId'] ?? null,
            'time_limit'   => $d['timeLimit'] ?? null,
            'passengers'   => $v['passengers'],
            'meta'         => ['book' => $d],
        ]);

        return response()->json(['success' => true, 'data' => $booking], 201);
    }

    private function checkoutPesawat(Request $request)
    {
        $v = $request->validate([
            'airline'        => 'required|string',
            'departure'      => 'required|string|size:3',
            'arrival'        => 'required|string|size:3',
            'departure_date' => 'required|date_format:Y-m-d',
            'adult'          => 'required|integer|min:1|max:7',
            'child'          => 'nullable|integer|min:0|max:7',
            'infant'         => 'nullable|integer|min:0|max:4',
            'price'          => 'required|numeric',  // harga dari fare
            'markup'         => 'nullable|integer|min:0',
            'flight_code'    => 'nullable|string',
            'departure_time' => 'nullable|string',
            'arrival_time'   => 'nullable|string',
            'class'          => 'nullable|string',
            'flights'        => 'required|array|min:1',
            'flights.*'      => 'required|string',
            'passengers.adults'   => 'required|array|min:1',
            'passengers.children' => 'nullable|array',
            'passengers.infants'  => 'nullable|array',
        ]);

        $adult = (int) $v['adult']; $child = (int) ($v['child'] ?? 0); $infant = (int) ($v['infant'] ?? 0);
        $payingPax = $adult + $child;
        $markup = (int) ($v['markup'] ?? SettingController::travelMarkup()['amount']);

        $mapPax = fn($p) => [
            'title' => $p['title'] ?? 'MR', 'firstName' => $p['first_name'] ?? '',
            'lastName' => $p['last_name'] ?? '', 'birthdate' => $p['birthdate'] ?? '',
            'idNumber' => $p['id_number'] ?? '', 'phone' => $p['phone'] ?? '', 'email' => $p['email'] ?? '',
        ];

        $res = $this->travel->bookFlight([
            'airline'       => strtoupper($v['airline']),
            'departure'     => strtoupper($v['departure']),
            'arrival'       => strtoupper($v['arrival']),
            'departureDate' => $v['departure_date'],
            'returnDate'    => '',
            'adult'         => $adult, 'child' => $child, 'infant' => $infant,
            'flights'       => $v['flights'],
            'passengers'    => [
                'adults'   => array_map($mapPax, $v['passengers']['adults'] ?? []),
                'children' => array_map($mapPax, $v['passengers']['children'] ?? []),
                'infants'  => array_map($mapPax, $v['passengers']['infants'] ?? []),
            ],
        ]);

        if (!TravelService::isSuccess($res['rc'] ?? null)) {
            return response()->json(['success' => false, 'message' => $res['rd'] ?? TravelService::userMessage($res['rc'] ?? null)], 422);
        }

        $d = $res['data'] ?? [];
        $vendorPrice = (int) round(((float) $v['price']) * $payingPax);

        $booking = $this->createRecord($request, [
            'moda'         => 'pesawat',
            'airline'      => strtoupper($v['airline']),
            'origin'       => strtoupper($v['departure']),
            'destination'  => strtoupper($v['arrival']),
            'depart_date'  => $v['departure_date'],
            'depart_time'  => $v['departure_time'] ?? ($d['departureTime1'] ?? null),
            'arrive_time'  => $v['arrival_time'] ?? ($d['arrivalTime1'] ?? null),
            'service_name' => $v['flight_code'] ?? ($d['flightCode1'] ?? null),
            'class'        => $v['class'] ?? null,
            'pax'          => $payingPax,
            'vendor_price' => $vendorPrice,
            'markup'       => $markup,
            'total_price'  => $vendorPrice + $markup * $payingPax,
            'vendor_booking_code'   => $d['bookingCode'] ?? null,
            'vendor_transaction_id' => $d['transactionId'] ?? null,
            'time_limit'   => $d['timeLimitYMD'] ?? null,
            'passengers'   => $v['passengers'],
            'meta'         => ['book' => $d, 'paymentCode' => $d['paymentCode'] ?? ''],
        ]);

        return response()->json(['success' => true, 'data' => $booking], 201);
    }

    private function checkoutPelni(Request $request)
    {
        $v = $request->validate([
            'origin'           => 'required|integer',
            'origin_call'      => 'required',
            'destination'      => 'required|integer',
            'destination_call' => 'required',
            'departure_date'   => 'required|string',  // YYYYMMDD
            'ship_number'      => 'required|string',
            'ship_name'        => 'required|string',
            'sub_class'        => 'required|string',
            'pelabuhan_asal'   => 'required|string',
            'pelabuhan_tujuan' => 'required|string',
            'harga_dewasa'     => 'required|numeric',
            'harga_anak'       => 'nullable|numeric',
            'harga_infant'     => 'nullable|numeric',
            'markup'           => 'nullable|integer|min:0',
            'male'             => 'nullable|integer|min:0',
            'female'           => 'nullable|integer|min:0',
            'adult'            => 'required|integer|min:1|max:7',
            'child'            => 'nullable|integer|min:0|max:7',
            'infant'           => 'nullable|integer|min:0|max:4',
            'contact.email'    => 'required|email',
            'contact.phone'    => 'required|string',
            'passengers.adults'                    => 'required|array|min:1',
            'passengers.adults.*.name'             => 'required|string',
            'passengers.adults.*.birth_date'       => 'required|date_format:Y-m-d',
            'passengers.adults.*.identity_number'  => 'required|string',
            'passengers.adults.*.gender'           => 'required|in:M,F',
            'passengers.children'                  => 'nullable|array',
            'passengers.infants'                   => 'nullable|array',
        ]);

        $adult = (int) $v['adult']; $child = (int) ($v['child'] ?? 0); $infant = (int) ($v['infant'] ?? 0);
        $male = (int) ($v['male'] ?? 0); $female = (int) ($v['female'] ?? 0);
        $payingPax = $adult + $child;
        $markup = (int) ($v['markup'] ?? SettingController::travelMarkup()['amount']);

        $mapPax = fn($p) => [
            'name' => $p['name'], 'birthDate' => $p['birth_date'],
            'identityNumber' => $p['identity_number'], 'gender' => $p['gender'] ?? 'M',
        ];

        $res = $this->travel->bookPelni([
            'hargaDewasa'     => $v['harga_dewasa'],
            'hargaAnak'       => $v['harga_anak'] ?? 0,
            'hargaInfant'     => $v['harga_infant'] ?? 0,
            'pelabuhanAsal'   => $v['pelabuhan_asal'],
            'pelabuhanTujuan' => $v['pelabuhan_tujuan'],
            'shipName'        => $v['ship_name'],
            'origin'          => $v['origin'],
            'originCall'      => $v['origin_call'],
            'destination'     => $v['destination'],
            'destinationCall' => $v['destination_call'],
            'departureDate'   => $v['departure_date'],
            'shipNumber'      => $v['ship_number'],
            'subClass'        => $v['sub_class'],
            'male'            => $male, 'female' => $female,
            'adult'           => $adult, 'child' => $child, 'infant' => $infant,
            'isFamily'        => 'N',
            'contact'         => ['email' => $v['contact']['email'], 'phone' => $v['contact']['phone']],
            'passengers'      => [
                'adults'   => array_map($mapPax, $v['passengers']['adults'] ?? []),
                'children' => array_map($mapPax, $v['passengers']['children'] ?? []),
                'infants'  => array_map($mapPax, $v['passengers']['infants'] ?? []),
            ],
        ]);

        if (!TravelService::isSuccess($res['rc'] ?? null)) {
            return response()->json(['success' => false, 'message' => $res['rd'] ?? TravelService::userMessage($res['rc'] ?? null)], 422);
        }

        $d = $res['data'] ?? [];
        $vendorPrice = (int) round(((float) $v['harga_dewasa']) * $adult + ((float) ($v['harga_anak'] ?? 0)) * $child + ((float) ($v['harga_infant'] ?? 0)) * $infant);

        $booking = $this->createRecord($request, [
            'moda'         => 'pelni',
            'origin'       => (string) $v['origin'],
            'destination'  => (string) $v['destination'],
            'origin_name'  => $v['pelabuhan_asal'],
            'destination_name' => $v['pelabuhan_tujuan'],
            'depart_date'  => substr($v['departure_date'], 0, 4) . '-' . substr($v['departure_date'], 4, 2) . '-' . substr($v['departure_date'], 6, 2),
            'depart_time'  => $d['departureTime'] ?? null,
            'arrive_time'  => $d['arrivalTime'] ?? null,
            'service_name' => $v['ship_name'],
            'class'        => $v['sub_class'],
            'pax'          => $payingPax + $infant,
            'vendor_price' => $vendorPrice,
            'markup'       => $markup,
            'total_price'  => $vendorPrice + $markup * $payingPax,
            'vendor_transaction_id' => $d['transactionId'] ?? null,
            'time_limit'   => $d['payLimit'] ?? null,
            'passengers'   => $v['passengers'],
            'meta'         => ['book' => $d, 'paymentCode' => $d['paymentCode'] ?? ''],
        ]);

        return response()->json(['success' => true, 'data' => $booking], 201);
    }

    private function createRecord(Request $request, array $attrs): TravelBooking
    {
        return TravelBooking::create(array_merge([
            'user_id' => $request->user()->id,
            'code'    => TravelBooking::generateCode(),
            'status'  => 'pending_payment',
        ], $attrs));
    }

    /* ── ADMIN: verifikasi transfer → terbitkan e-tiket ─────────────── */

    /**
     * Admin list travel bookings (filter status). Untuk verifikasi pembayaran manual.
     * GET /admin/travel/bookings?status=pending_payment
     */
    public function adminBookings(Request $request)
    {
        $q = TravelBooking::with('user:id,name,email')->latest();
        if ($request->status) $q->where('status', $request->status);
        if ($request->moda)   $q->where('moda', $request->moda);
        if ($request->search) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('code', 'like', "%$s%")
                ->orWhere('origin_name', 'like', "%$s%")
                ->orWhere('destination_name', 'like', "%$s%"));
        }
        return response()->json(['success' => true, 'data' => $q->paginate($request->limit ?? 20)]);
    }

    /**
     * Admin terbitkan e-tiket setelah verifikasi transfer masuk.
     * POST /admin/travel/bookings/{id}/issue   body: { simulate?: bool }
     */
    public function adminIssue(Request $request, string $id)
    {
        $booking = TravelBooking::findOrFail($id);

        if ($booking->status === 'issued') {
            return response()->json(['success' => true, 'data' => $booking, 'message' => 'Tiket sudah terbit.']);
        }
        if (!in_array($booking->status, ['pending_payment', 'paid'])) {
            return response()->json(['success' => false, 'message' => 'Status pesanan tidak bisa diterbitkan.'], 422);
        }

        $simulate = $request->boolean('simulate', false); // admin verifikasi → terbit riil
        $res = $this->issueBooking($booking, $simulate);

        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json(['success' => true, 'data' => $booking->fresh(), 'message' => 'E-tiket berhasil diterbitkan.']);
    }

    /** Eksekusi issue ke Rajabiller + update booking + kirim e-tiket email. */
    private function issueBooking(TravelBooking $booking, bool $simulate): array
    {
        if ($booking->moda === 'kereta') {
            $book = $booking->meta['book'] ?? [];
            $res = $this->travel->payTrain($booking->vendor_booking_code, $booking->vendor_transaction_id, [
                'nominal'       => $book['normalSales'] ?? $book['bookBalance'] ?? $booking->vendor_price,
                'nominal_admin' => $book['nominalAdmin'] ?? 0,
                'discount'      => 0,
            ]);
        } elseif ($booking->moda === 'pelni') {
            $res = $this->travel->payPelni([
                'paymentCode'   => $booking->meta['paymentCode'] ?? '',
                'transactionId' => $booking->vendor_transaction_id,
                'simulate'      => $simulate,
            ]);
        } else { // pesawat
            $res = $this->travel->payFlight(
                $booking->airline,
                $booking->vendor_transaction_id,
                $booking->vendor_booking_code,
                ['paymentCode' => $booking->meta['paymentCode'] ?? '', 'simulate' => $simulate],
            );
        }

        if (!TravelService::isSuccess($res['rc'] ?? null)) {
            return ['ok' => false, 'message' => $res['rd'] ?? TravelService::userMessage($res['rc'] ?? null)];
        }

        $d = $res['data'] ?? [];
        $booking->update([
            'status'      => 'issued',
            'paid_at'     => now(),
            'issued_at'   => now(),
            'url_etiket'  => $d['url_etiket'] ?? null,
            'url_struk'   => $d['url_struk'] ?? null,
            'url_image'   => $d['url_image'] ?? null,
            'meta'        => array_merge($booking->meta ?? [], ['payment' => $d]),
        ]);

        $this->sendEtiketEmail($booking->fresh(), $booking->user);
        return ['ok' => true, 'message' => 'ok'];
    }

    /** Kirim e-tiket (email + PDF lampiran). Tidak boleh menggagalkan response issue. */
    private function sendEtiketEmail(TravelBooking $booking, $user): void
    {
        $email = $booking->meta['payment']['contact']['email']
            ?? $booking->meta['book']['contact']['email']
            ?? $user?->email;
        if (!$email) return;

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TravelIssuedMail($booking));
        } catch (\Throwable $e) {
            logger()->error('Travel e-tiket email gagal', ['code' => $booking->code, 'error' => $e->getMessage()]);
        }
    }

    /* ── READ ───────────────────────────────────────────────────────── */

    public function myBookings(Request $request)
    {
        $items = TravelBooking::where('user_id', $request->user()->id)
            ->latest()->limit(100)->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function show(Request $request, string $id)
    {
        $booking = TravelBooking::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        return response()->json(['success' => true, 'data' => $booking]);
    }

    /** Stream PDF e-tiket untuk di-download customer. */
    public function downloadEtiket(Request $request, string $id)
    {
        $booking = TravelBooking::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        if (!in_array($booking->status, ['paid', 'issued'])) {
            return response()->json(['success' => false, 'message' => 'E-tiket tersedia setelah pembayaran berhasil.'], 400);
        }
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.travel-ticket', \App\Mail\TravelIssuedMail::payload($booking))
            ->setPaper('a4', 'portrait');
        return $pdf->download("E-Tiket-{$booking->code}.pdf");
    }
}
