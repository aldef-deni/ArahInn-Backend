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
        // Hitung total + validasi promo SEBELUM booking vendor (hindari booking sia-sia)
        $vendorPrice = (int) round(((float) $v['price_adult']) * $adult);
        $markup      = SettingController::computeTravelFee('kereta', $vendorPrice, $adult); // total biaya penanganan (0 = tak tampil)
        $total       = $vendorPrice + $markup;
        [$promo, $promoDiscount] = $this->resolveTravelPromo($request->input('promo_code'), 'kereta', (float) $total, $v['date']);

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
            'total_price'  => $total - $promoDiscount,
            'promo_id'       => $promo?->id,
            'promo_discount' => $promoDiscount,
            'vendor_booking_code'   => $d['bookingCode'] ?? null,
            'vendor_transaction_id' => $d['transactionId'] ?? null,
            'time_limit'   => $d['timeLimit'] ?? null,
            'passengers'   => $v['passengers'],
            'meta'         => ['book' => $d],
        ]);
        if ($promo) $promo->increment('used_count');

        return response()->json(['success' => true, 'data' => $booking], 201);
    }

    private function checkoutPesawat(Request $request)
    {
        // Pulang-pergi: 2 leg (pergi + pulang) → 1 order (group) → 1 pembayaran → 2 e-tiket.
        // ⚠️ Modul PP DIMATIKAN sementara (belum stabil di vendor). Hapus blok ini untuk
        // mengaktifkan kembali (method checkoutPesawatRoundTrip tetap ada di bawah).
        if ($request->input('trip_type') === 'roundtrip') {
            return response()->json([
                'success' => false,
                'message' => 'Pemesanan tiket pulang-pergi sementara belum tersedia. Silakan pesan tiket sekali jalan (one-way) untuk keberangkatan dan kepulangan secara terpisah.',
            ], 422);
            // return $this->checkoutPesawatRoundTrip($request);
        }

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
        // Hitung total + validasi promo SEBELUM booking vendor
        $vendorPrice = (int) round(((float) $v['price']) * $payingPax);
        $markup      = SettingController::computeTravelFee('pesawat', $vendorPrice, $payingPax); // total biaya penanganan (0 = tak tampil)
        $total       = $vendorPrice + $markup;
        [$promo, $promoDiscount] = $this->resolveTravelPromo($request->input('promo_code'), 'pesawat', (float) $total, $v['departure_date']);

        // WNI → idNumber = NIK; WNA → idNumber jatuh ke nomor paspor (identitas yang dipakai vendor)
        $mapPax = fn($p) => [
            'title' => $p['title'] ?? 'MR', 'firstName' => $p['first_name'] ?? '',
            'lastName' => $p['last_name'] ?? '', 'birthdate' => $p['birthdate'] ?? '',
            'idNumber' => ($p['id_number'] ?? '') ?: ($p['passport_number'] ?? ''),
            'phone' => $p['phone'] ?? '', 'email' => $p['email'] ?? '',
            'nationality' => $p['nationality'] ?? 'ID', 'passportNumber' => $p['passport_number'] ?? '',
            'passportIssueDate' => $p['passport_issue_date'] ?? '', 'passportIssuingCountry' => $p['passport_issuing_country'] ?? '',
            'passportExpiry' => $p['passport_expiry'] ?? '',
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
            'total_price'  => $total - $promoDiscount,
            'promo_id'       => $promo?->id,
            'promo_discount' => $promoDiscount,
            'vendor_booking_code'   => $d['bookingCode'] ?? null,
            'vendor_transaction_id' => $d['transactionId'] ?? null,
            'time_limit'   => $d['timeLimitYMD'] ?? null,
            'passengers'   => $v['passengers'],
            'meta'         => ['book' => $d, 'paymentCode' => $d['paymentCode'] ?? ''],
        ]);
        if ($promo) $promo->increment('used_count');

        return response()->json(['success' => true, 'data' => $booking], 201);
    }

    /**
     * Book 1 leg penerbangan ke vendor + siapkan atribut record (belum disimpan).
     * @return array { ok:bool, message?:string, vendorPrice:int, legTotal:int, attrs:array }
     */
    private function bookFlightLeg(array $leg, array $passengers, int $adult, int $child, int $infant, int $markup): array
    {
        $payingPax = $adult + $child;
        // WNI → idNumber = NIK; WNA → idNumber jatuh ke nomor paspor (identitas yang dipakai vendor)
        $mapPax = fn($p) => [
            'title' => $p['title'] ?? 'MR', 'firstName' => $p['first_name'] ?? '',
            'lastName' => $p['last_name'] ?? '', 'birthdate' => $p['birthdate'] ?? '',
            'idNumber' => ($p['id_number'] ?? '') ?: ($p['passport_number'] ?? ''),
            'phone' => $p['phone'] ?? '', 'email' => $p['email'] ?? '',
            'nationality' => $p['nationality'] ?? 'ID', 'passportNumber' => $p['passport_number'] ?? '',
            'passportIssueDate' => $p['passport_issue_date'] ?? '', 'passportIssuingCountry' => $p['passport_issuing_country'] ?? '',
            'passportExpiry' => $p['passport_expiry'] ?? '',
        ];

        $res = $this->travel->bookFlight([
            'airline'       => strtoupper($leg['airline']),
            'departure'     => strtoupper($leg['departure']),
            'arrival'       => strtoupper($leg['arrival']),
            'departureDate' => $leg['departure_date'],
            'returnDate'    => '',
            'adult'         => $adult, 'child' => $child, 'infant' => $infant,
            'flights'       => $leg['flights'],
            'passengers'    => [
                'adults'   => array_map($mapPax, $passengers['adults'] ?? []),
                'children' => array_map($mapPax, $passengers['children'] ?? []),
                'infants'  => array_map($mapPax, $passengers['infants'] ?? []),
            ],
        ]);

        if (!TravelService::isSuccess($res['rc'] ?? null)) {
            return ['ok' => false, 'message' => $res['rd'] ?? TravelService::userMessage($res['rc'] ?? null)];
        }

        $d = $res['data'] ?? [];
        $vendorPrice = (int) round(((float) $leg['price']) * $payingPax);
        $legTotal    = $vendorPrice + $markup * $payingPax;

        return [
            'ok' => true, 'vendorPrice' => $vendorPrice, 'legTotal' => $legTotal,
            'attrs' => [
                'moda'         => 'pesawat',
                'airline'      => strtoupper($leg['airline']),
                'origin'       => strtoupper($leg['departure']),
                'destination'  => strtoupper($leg['arrival']),
                'depart_date'  => $leg['departure_date'],
                'depart_time'  => $leg['departure_time'] ?? ($d['departureTime1'] ?? null),
                'arrive_time'  => $leg['arrival_time'] ?? ($d['arrivalTime1'] ?? null),
                'service_name' => $leg['flight_code'] ?? ($d['flightCode1'] ?? null),
                'class'        => $leg['class'] ?? null,
                'pax'          => $payingPax,
                'vendor_price' => $vendorPrice,
                'markup'       => $markup,
                'total_price'  => $legTotal,
                'vendor_booking_code'   => $d['bookingCode'] ?? null,
                'vendor_transaction_id' => $d['transactionId'] ?? null,
                'time_limit'   => $d['timeLimitYMD'] ?? null,
                'passengers'   => $passengers,
                'meta'         => ['book' => $d, 'paymentCode' => $d['paymentCode'] ?? ''],
            ],
        ];
    }

    /**
     * Checkout PULANG-PERGI: book leg pergi + pulang (boleh beda maskapai),
     * buat 2 record berbagi group_code, total = gabungan (1 pembayaran).
     */
    private function checkoutPesawatRoundTrip(Request $request)
    {
        $v = $request->validate([
            'adult'   => 'required|integer|min:1|max:7',
            'child'   => 'nullable|integer|min:0|max:7',
            'infant'  => 'nullable|integer|min:0|max:4',
            'markup'  => 'nullable|integer|min:0',
            'passengers.adults'   => 'required|array|min:1',
            'passengers.children' => 'nullable|array',
            'passengers.infants'  => 'nullable|array',
            'outbound.airline'        => 'required|string',
            'outbound.departure'      => 'required|string|size:3',
            'outbound.arrival'        => 'required|string|size:3',
            'outbound.departure_date' => 'required|date_format:Y-m-d',
            'outbound.price'          => 'required|numeric',
            'outbound.flights'        => 'required|array|min:1',
            'outbound.flights.*'      => 'required|string',
            'outbound.flight_code'    => 'nullable|string',
            'outbound.departure_time' => 'nullable|string',
            'outbound.arrival_time'   => 'nullable|string',
            'outbound.class'          => 'nullable|string',
            'return.airline'        => 'required|string',
            'return.departure'      => 'required|string|size:3',
            'return.arrival'        => 'required|string|size:3',
            'return.departure_date' => 'required|date_format:Y-m-d',
            'return.price'          => 'required|numeric',
            'return.flights'        => 'required|array|min:1',
            'return.flights.*'      => 'required|string',
            'return.flight_code'    => 'nullable|string',
            'return.departure_time' => 'nullable|string',
            'return.arrival_time'   => 'nullable|string',
            'return.class'          => 'nullable|string',
        ]);

        $adult = (int) $v['adult']; $child = (int) ($v['child'] ?? 0); $infant = (int) ($v['infant'] ?? 0);
        $markup = (int) ($v['markup'] ?? SettingController::travelMarkup()['amount']);
        $passengers = [
            'adults'   => $v['passengers']['adults'] ?? [],
            'children' => $v['passengers']['children'] ?? [],
            'infants'  => $v['passengers']['infants'] ?? [],
        ];

        // Leg PERGI
        $out = $this->bookFlightLeg($v['outbound'], $passengers, $adult, $child, $infant, $markup);
        if (!$out['ok']) {
            return response()->json(['success' => false, 'message' => 'Gagal booking penerbangan pergi: ' . $out['message']], 422);
        }
        // Leg PULANG (kalau gagal, hold leg pergi akan kedaluwarsa sendiri sesuai time limit vendor)
        $ret = $this->bookFlightLeg($v['return'], $passengers, $adult, $child, $infant, $markup);
        if (!$ret['ok']) {
            return response()->json(['success' => false, 'message' => 'Penerbangan pergi tersedia, tapi gagal booking penerbangan pulang: ' . $ret['message'] . '. Silakan ulangi pencarian.'], 422);
        }

        // Total gabungan + promo atas total gabungan
        $combined = $out['legTotal'] + $ret['legTotal'];
        [$promo, $promoDiscount] = $this->resolveTravelPromo($request->input('promo_code'), 'pesawat', (float) $combined, $v['outbound']['departure_date']);

        $group = 'TRVG' . now()->format('ymd') . strtoupper(substr(uniqid(), -5));

        // Diskon dibebankan ke leg pergi (representatif). Total order = jumlah kedua leg.
        $bDepart = $this->createRecord($request, array_merge($out['attrs'], [
            'group_code'     => $group, 'leg' => 'depart',
            'total_price'    => max(0, $out['legTotal'] - (int) round($promoDiscount)),
            'promo_id'       => $promo?->id,
            'promo_discount' => $promoDiscount,
        ]));
        $bReturn = $this->createRecord($request, array_merge($ret['attrs'], [
            'group_code' => $group, 'leg' => 'return',
        ]));
        if ($promo) $promo->increment('used_count');

        return response()->json([
            'success' => true,
            'data' => [
                'group_code'  => $group,
                'trip_type'   => 'roundtrip',
                'depart'      => $bDepart,
                'return'      => $bReturn,
                'total_price' => $bDepart->total_price + $bReturn->total_price,
            ],
        ], 201);
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
        // Hitung total + validasi promo SEBELUM booking vendor
        $vendorPrice = (int) round(((float) $v['harga_dewasa']) * $adult + ((float) ($v['harga_anak'] ?? 0)) * $child + ((float) ($v['harga_infant'] ?? 0)) * $infant);
        $markup      = SettingController::computeTravelFee('pelni', $vendorPrice, $payingPax); // total biaya penanganan (0 = tak tampil)
        $adminFee    = SettingController::travelAdminFee('pelni');                              // biaya admin flat (0 = tak tampil)
        $total       = $vendorPrice + $markup + $adminFee;
        $departYmd   = substr($v['departure_date'], 0, 4) . '-' . substr($v['departure_date'], 4, 2) . '-' . substr($v['departure_date'], 6, 2);
        [$promo, $promoDiscount] = $this->resolveTravelPromo($request->input('promo_code'), 'pelni', (float) $total, $departYmd);

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

        $booking = $this->createRecord($request, [
            'moda'         => 'pelni',
            'origin'       => (string) $v['origin'],
            'destination'  => (string) $v['destination'],
            'origin_name'  => $v['pelabuhan_asal'],
            'destination_name' => $v['pelabuhan_tujuan'],
            'depart_date'  => $departYmd,
            'depart_time'  => $d['departureTime'] ?? null,
            'arrive_time'  => $d['arrivalTime'] ?? null,
            'service_name' => $v['ship_name'],
            'class'        => $v['sub_class'],
            'pax'          => $payingPax + $infant,
            'vendor_price' => $vendorPrice,
            'markup'       => $markup,
            'admin_fee'    => $adminFee,
            'total_price'  => $total - $promoDiscount,
            'promo_id'       => $promo?->id,
            'promo_discount' => $promoDiscount,
            'vendor_transaction_id' => $d['transactionId'] ?? null,
            'time_limit'   => $d['payLimit'] ?? null,
            'passengers'   => $v['passengers'],
            'meta'         => ['book' => $d, 'paymentCode' => $d['paymentCode'] ?? ''],
        ]);
        if ($promo) $promo->increment('used_count');

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

    /**
     * Validasi kode promo untuk pembelian tiket (dipanggil SEBELUM booking vendor).
     * Return [Promo|null, float discount]. Throw 422 kalau kode/kondisi tidak valid.
     */
    private function resolveTravelPromo(?string $code, string $moda, float $total, ?string $departDate): array
    {
        $code = trim((string) $code);
        if ($code === '') return [null, 0.0];

        $promo = \App\Models\Promo::where('code', $code)->active()->first();
        if (!$promo) {
            abort(response()->json(['success' => false, 'message' => 'Kode promo tidak valid atau sudah kadaluarsa.'], 422));
        }
        // Hanya promo platform (owner_id null) yang berlaku untuk tiket.
        if ($promo->owner_id !== null) {
            abort(response()->json(['success' => false, 'message' => 'Kode promo tidak berlaku untuk pembelian tiket.'], 422));
        }
        // Kondisi: product type = moda (pesawat/pelni/kereta), hari berlaku pakai tgl berangkat.
        if ($err = $promo->conditionError(null, $departDate, $moda)) {
            abort(response()->json(['success' => false, 'message' => $err], 422));
        }
        if ($promo->quota !== null && $promo->used_count >= $promo->quota) {
            abort(response()->json(['success' => false, 'message' => 'Kuota promo sudah habis.'], 422));
        }
        if ($total < (float) $promo->min_purchase) {
            abort(response()->json(['success' => false, 'message' => 'Minimum pembelian Rp ' . number_format($promo->min_purchase, 0, ',', '.') . ' untuk promo ini.'], 422));
        }

        $discount = round($promo->calculateDiscount($total), 2);
        $discount = min($discount, $total); // jaga agar tidak melebihi total
        return [$promo, $discount];
    }

    /**
     * Preview kode promo tiket (untuk tombol "Gunakan" di FE) — tanpa booking.
     * POST /travel/promo/validate
     */
    public function validatePromo(Request $request)
    {
        $v = $request->validate([
            'code'        => 'required|string',
            'moda'        => 'required|in:pesawat,pelni,kereta',
            'total'       => 'required|numeric|min:0',
            'depart_date' => 'nullable|date',
        ]);

        // resolveTravelPromo akan abort 422 dgn pesan jelas kalau tidak valid/kondisi gagal.
        [$promo, $discount] = $this->resolveTravelPromo($v['code'], $v['moda'], (float) $v['total'], $v['depart_date'] ?? null);

        return response()->json([
            'success' => true,
            'data'    => [
                'code'     => $promo->code,
                'name'     => $promo->name,
                'discount' => $discount,
                'final'    => max(0, round($v['total'] - $discount, 2)),
            ],
        ]);
    }

    /* ── ADMIN: verifikasi transfer → terbitkan e-tiket ─────────────── */

    /**
     * Admin list travel bookings (filter status). Untuk verifikasi pembayaran manual.
     * GET /admin/travel/bookings?status=pending_payment
     */
    /**
     * Hapus massal pesanan tiket travel (HARD DELETE).
     * GATE KERAS: hanya akun superadmin email aldeftech@gmail.com.
     */
    public function adminBulkDestroy(Request $request)
    {
        $user = $request->user();
        if (!$user || strtolower(trim((string) $user->email)) !== 'aldeftech@gmail.com') {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Fitur hapus pesanan khusus akun tertentu.',
            ], 403);
        }

        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        // travel_bookings = tabel leaf (tak ada FK child) → aman langsung hapus.
        $deleted = TravelBooking::whereIn('id', $ids)->delete();

        return response()->json([
            'success' => true,
            'message' => "Berhasil menghapus {$deleted} pesanan tiket travel.",
            'deleted' => $deleted,
        ]);
    }

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
        $simulate = $request->boolean('simulate', false); // admin verifikasi → terbit riil

        // Pulang-pergi: terbitkan SEMUA leg dalam group sekaligus (1 klik → 2 e-tiket).
        if ($booking->group_code) {
            $legs = TravelBooking::with('user:id,name,email')
                ->where('group_code', $booking->group_code)->orderBy('leg')->get();

            // ── PRA-CEK (cegah penerbitan SETENGAH JADI / partial) ────────────
            // Tiket yang sudah terbit TIDAK bisa di-un-issue. Maka sebelum bayar
            // leg mana pun, pastikan SEMUA leg yang belum terbit memang layak
            // (status pending_payment/paid + hold vendor belum kedaluwarsa).
            // Kalau ada yang tak layak DAN belum ada leg yang terbit → batalkan
            // total: tidak ada leg yang dibayar/diterbitkan → tidak ada tiket separuh.
            $anyIssued = $legs->contains(fn ($l) => $l->status === 'issued');
            $blocked = [];
            foreach ($legs as $leg) {
                if ($leg->status === 'issued') continue;
                if (!in_array($leg->status, ['pending_payment', 'paid'], true)) {
                    $blocked[] = ['code' => $leg->code, 'leg' => $leg->leg, 'reason' => 'status ' . $leg->status . ' tidak bisa diterbitkan'];
                } elseif (!$simulate && $this->holdExpired($leg)) {
                    $blocked[] = ['code' => $leg->code, 'leg' => $leg->leg, 'reason' => 'batas waktu hold vendor sudah lewat'];
                }
            }
            if ($blocked && !$anyIssued) {
                return response()->json([
                    'success' => false,
                    'blocked' => $blocked,
                    'message' => 'Penerbitan dibatalkan — ada leg yang tidak bisa diterbitkan (hold kedaluwarsa / status tidak valid). TIDAK ada leg yang diterbitkan (tidak ada tiket separuh). Silakan rebook penerbangan atau proses refund.',
                ], 422);
            }

            // ── TERBITKAN tiap leg yang belum terbit ──────────────────────────
            $results = [];
            foreach ($legs as $leg) {
                if ($leg->status === 'issued') {
                    $results[] = ['code' => $leg->code, 'leg' => $leg->leg, 'ok' => true, 'message' => 'sudah terbit'];
                    continue;
                }
                if (!in_array($leg->status, ['pending_payment', 'paid'], true)
                    || (!$simulate && $this->holdExpired($leg))) {
                    $results[] = ['code' => $leg->code, 'leg' => $leg->leg, 'ok' => false, 'message' => 'tidak layak diterbitkan'];
                    continue;
                }
                // Email ditahan → dikelola setelah loop sesuai hasil (gabungan / partial).
                $r = $this->issueBooking($leg, $simulate, sendEmail: false);
                $results[] = ['code' => $leg->code, 'leg' => $leg->leg, 'ok' => $r['ok'], 'message' => $r['message']];
            }

            $fresh = TravelBooking::with('user:id,name,email')
                ->where('group_code', $booking->group_code)->orderBy('leg')->get();
            $issuedAll = $fresh->every(fn ($l) => $l->status === 'issued');
            $issuedAny = $fresh->contains(fn ($l) => $l->status === 'issued');

            // ── EMAIL ─────────────────────────────────────────────────────────
            if ($issuedAll) {
                $this->sendGroupEtiketEmail($fresh);                 // 1 email gabungan (2 e-tiket dalam 1 PDF)
            } elseif ($issuedAny) {
                // PARTIAL (mis. saldo vendor habis di leg ke-2): JANGAN tahan total.
                // Kirim e-tiket leg yang BERHASIL terbit supaya customer tetap dapat tiketnya.
                foreach ($fresh->where('status', 'issued') as $leg) {
                    $this->sendEtiketEmail($leg, $leg->user);
                }
            }

            return response()->json([
                'success' => $issuedAll,
                'partial' => $issuedAny && !$issuedAll,
                'data'    => $fresh,
                'results' => $results,
                'message' => $issuedAll
                    ? 'E-tiket pulang-pergi (2 leg) berhasil diterbitkan — 1 email berisi 2 e-tiket telah dikirim.'
                    : ($issuedAny
                        ? '⚠️ PARTIAL: sebagian leg terbit, sebagian gagal (cek saldo Rajabiller / status per leg). E-tiket leg yang berhasil SUDAH dikirim ke customer. Segera tindak lanjuti leg yang gagal — retry penerbitan atau refund bagian itu.'
                        : 'Semua leg gagal diterbitkan — tidak ada tiket terbit. Cek saldo Rajabiller / status, lalu retry atau refund.'),
            ], $issuedAll ? 200 : 422);
        }

        // One-way
        if ($booking->status === 'issued') {
            return response()->json(['success' => true, 'data' => $booking, 'message' => 'Tiket sudah terbit.']);
        }
        if (!in_array($booking->status, ['pending_payment', 'paid'])) {
            return response()->json(['success' => false, 'message' => 'Status pesanan tidak bisa diterbitkan.'], 422);
        }

        $res = $this->issueBooking($booking, $simulate);
        if (!$res['ok']) {
            return response()->json(['success' => false, 'message' => $res['message']], 422);
        }
        return response()->json(['success' => true, 'data' => $booking->fresh(), 'message' => 'E-tiket berhasil diterbitkan.']);
    }

    /**
     * Admin batalkan pesanan travel (yang belum terbit).
     * POST /admin/travel/bookings/{id}/cancel   body: { reason?: string }
     *
     * Hanya untuk status pending_payment / paid. Tiket yang sudah 'issued'
     * tidak bisa dibatalkan dari sini (sudah terbit ke operator).
     */
    public function adminCancel(Request $request, string $id)
    {
        $booking = TravelBooking::findOrFail($id);

        if ($booking->status === 'canceled') {
            return response()->json(['success' => true, 'data' => $booking, 'message' => 'Pesanan sudah dibatalkan.']);
        }
        if ($booking->status === 'issued') {
            return response()->json(['success' => false, 'message' => 'E-tiket sudah terbit, tidak bisa dibatalkan dari sini.'], 422);
        }
        if (!in_array($booking->status, ['pending_payment', 'paid'])) {
            return response()->json(['success' => false, 'message' => 'Status pesanan tidak bisa dibatalkan.'], 422);
        }

        $reason = trim((string) $request->input('reason', ''));
        $booking->update([
            'status' => 'canceled',
            'meta'   => array_merge($booking->meta ?? [], [
                'cancel' => [
                    'reason' => $reason ?: 'Dibatalkan admin',
                    'by'     => $request->user()->id,
                    'by_name'=> $request->user()->name,
                    'at'     => now()->toIso8601String(),
                ],
            ]),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $booking->fresh(),
            'message' => 'Pesanan travel dibatalkan.',
        ]);
    }

    /**
     * Hold vendor sudah kedaluwarsa? Defensif: hanya percaya kalau time_limit
     * ter-parse jadi tanggal yang masuk akal (cegah salah-parse jadi epoch/far-future
     * yang bisa salah-blok). Kalau ragu → false (biarkan vendor yang menolak).
     */
    private function holdExpired(TravelBooking $leg): bool
    {
        $tl = $leg->time_limit;   // Carbon|null (cast datetime di model)
        if (!$tl) return false;
        $year = (int) $tl->format('Y');
        if ($year < 2024 || $year > ((int) now()->format('Y')) + 2) return false;
        return $tl->isPast();
    }

    /** Eksekusi issue ke Rajabiller + update booking + kirim e-tiket email. */
    private function issueBooking(TravelBooking $booking, bool $simulate, bool $sendEmail = true): array
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
            $res = $this->travel->payFlight([
                'airline'       => $booking->airline,
                'transactionId' => $booking->vendor_transaction_id,
                'bookingCode'   => $booking->vendor_booking_code,
                'paymentCode'   => $booking->meta['paymentCode'] ?? '',
                'simulate'      => $simulate,
            ]);
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

        // Notifikasi e-tiket terbit (in-app + push Expo) → tap di app diarahkan ke detail tiket.
        try {
            $modaLabel = ['pesawat' => 'Pesawat', 'pelni' => 'Kapal Laut', 'kereta' => 'Kereta'][$booking->moda] ?? 'Travel';
            $rute = ($booking->origin_name ?: $booking->origin) . ' → ' . ($booking->destination_name ?: $booking->destination);
            \App\Services\NotificationService::send(
                (int) $booking->user_id,
                'travel_issued',
                'E-Tiket Terbit',
                "E-tiket {$modaLabel} {$booking->code} ({$rute}) sudah terbit. Ketuk untuk melihat tiket.",
                ['booking_id' => $booking->id, 'code' => $booking->code, 'moda' => $booking->moda]
            );
        } catch (\Throwable $e) { /* notifikasi tak boleh menggagalkan penerbitan tiket */ }

        // PP: email ditahan, dikirim sekali (gabungan) setelah kedua leg terbit (lihat adminIssue).
        if ($sendEmail) {
            $this->sendEtiketEmail($booking->fresh(), $booking->user);
        }
        return ['ok' => true, 'message' => 'ok'];
    }

    /** Email penerima e-tiket (contact saat bayar/book → fallback user). */
    private function recipientEmail(TravelBooking $booking, $user): ?string
    {
        return $booking->meta['payment']['contact']['email']
            ?? $booking->meta['book']['contact']['email']
            ?? $user?->email;
    }

    /** Kirim e-tiket TUNGGAL (email + PDF lampiran). Tidak boleh menggagalkan response issue. */
    private function sendEtiketEmail(TravelBooking $booking, $user): void
    {
        $email = $this->recipientEmail($booking, $user);
        if (!$email) return;

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TravelIssuedMail($booking));
        } catch (\Throwable $e) {
            logger()->error('Travel e-tiket email gagal', ['code' => $booking->code, 'error' => $e->getMessage()]);
        }
    }

    /** Kirim e-tiket PULANG-PERGI gabungan: 1 email + 1 PDF (2 leg). */
    private function sendGroupEtiketEmail(\Illuminate\Support\Collection $legs): void
    {
        $first = $legs->first();
        if (!$first) return;
        $email = $this->recipientEmail($first, $first->user);
        if (!$email) return;

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TravelIssuedGroupMail($legs->values()));
        } catch (\Throwable $e) {
            logger()->error('Travel e-tiket PP email gagal', ['group' => $first->group_code, 'error' => $e->getMessage()]);
        }
    }

    /* ── READ ───────────────────────────────────────────────────────── */

    public function myBookings(Request $request)
    {
        $items = TravelBooking::where('user_id', $request->user()->id)
            ->latest()->limit(100)->get();
        return response()->json(['success' => true, 'data' => $items]);
    }

    /** Cari booking milik user berdasarkan kode (TRV…) atau id numerik. */
    private function findUserBooking(Request $request, string $key): TravelBooking
    {
        $q = TravelBooking::where('user_id', $request->user()->id);
        ctype_digit($key) ? $q->where('id', $key) : $q->where('code', $key);
        return $q->firstOrFail();
    }

    public function show(Request $request, string $id)
    {
        $booking = $this->findUserBooking($request, $id);
        $data = $booking->toArray();
        // Pulang-pergi: sertakan semua leg dalam group + total order gabungan.
        if ($booking->group_code) {
            $legs = TravelBooking::where('group_code', $booking->group_code)->orderBy('leg')->get();
            $data['group_legs']   = $legs;
            $data['group_total']  = (int) $legs->sum('total_price');
            $data['is_roundtrip'] = true;
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Stream PDF e-tiket untuk di-download customer. */
    public function downloadEtiket(Request $request, string $id)
    {
        $booking = $this->findUserBooking($request, $id);
        if (!in_array($booking->status, ['paid', 'issued'])) {
            return response()->json(['success' => false, 'message' => 'E-tiket tersedia setelah pembayaran berhasil.'], 400);
        }
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.travel-ticket', \App\Mail\TravelIssuedMail::payload($booking))
            ->setPaper('a4', 'portrait');
        return $pdf->download("E-Tiket-{$booking->code}.pdf");
    }
}
