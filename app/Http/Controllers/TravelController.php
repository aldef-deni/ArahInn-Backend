<?php

namespace App\Http\Controllers;

use App\Services\TravelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * TravelController — endpoint kereta (Rajabiller Travel API langsung).
 *
 * Public (read-only, aman): stations, search.
 * Auth (butuh login): book, seatLayout, changeSeat, cancel, pay, status.
 *
 * Pola response: rc "00" → { success:true, data }, selain itu → { success:false, message }.
 */
class TravelController extends Controller
{
    public function __construct(private TravelService $travel) {}

    /* ── PUBLIC: read-only ──────────────────────────────────────────── */

    /** Setting travel publik (biaya penanganan per moda) untuk ditampilkan di harga. */
    public function settings()
    {
        return response()->json([
            'success' => true,
            'data'    => [
                // Biaya penanganan per moda: { pesawat:{amount,percent}, pelni:{...}, kereta:{...} }
                'service_fees'   => \App\Http\Controllers\Admin\SettingController::travelServiceFee(),
                // Backward-compat (lama) — tidak dipakai lagi oleh FE baru.
                'markup_per_pax' => \App\Http\Controllers\Admin\SettingController::travelMarkup()['amount'],
            ],
        ]);
    }

    /** Daftar stasiun (cache 24 jam — jarang berubah). */
    public function stations()
    {
        $data = Cache::remember('travel:train:stations', 86400, function () {
            $res = $this->travel->stations();
            return TravelService::isSuccess($res['rc'] ?? null) ? ($res['data'] ?? []) : null;
        });

        if ($data === null) {
            Cache::forget('travel:train:stations');
            return response()->json(['success' => false, 'message' => 'Gagal memuat daftar stasiun.'], 502);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Cari jadwal kereta. */
    public function search(Request $request)
    {
        $v = $request->validate([
            'origin'      => 'required|string|min:2|max:5',  // kode stasiun mis BD, GMR, KAC
            'destination' => 'required|string|min:2|max:5|different:origin',
            'date'        => 'required|date_format:Y-m-d|after_or_equal:today',
            'adult'       => 'required|integer|min:1|max:7',
            'infant'      => 'nullable|integer|min:0|max:4',
        ]);

        $res = $this->travel->searchTrain([
            'origin'      => strtoupper($v['origin']),
            'destination' => strtoupper($v['destination']),
            'date'        => $v['date'],
            'adult'       => $v['adult'],
            'infant'      => $v['infant'] ?? 0,
        ]);

        return $this->respond($res, $res['data'] ?? []);
    }

    /** Denah kursi gerbong. */
    public function seatLayout(Request $request)
    {
        $v = $request->validate([
            'origin'       => 'required|string|min:2|max:5',
            'destination'  => 'required|string|min:2|max:5',
            'date'         => 'required|date_format:Y-m-d',
            'train_number' => 'required|string',
        ]);

        $res = $this->travel->seatLayout([
            'origin'      => $v['origin'],
            'destination' => $v['destination'],
            'date'        => $v['date'],
            'trainNumber' => $v['train_number'],
        ]);
        return $this->respond($res, $res['data'] ?? []);
    }

    /* ── AUTH: booking flow ─────────────────────────────────────────── */

    /**
     * Booking kereta (belum bayar). Hasilkan bookingCode + transactionId + timeLimit.
     * Customer harus bayar sebelum timeLimit, lalu kita panggil pay().
     */
    public function book(Request $request)
    {
        // Catatan: frontend (axios interceptor) mengirim body dalam snake_case.
        // Jadi validasi pakai snake_case, lalu map ke camelCase untuk Rajabiller.
        $v = $request->validate([
            'origin'            => 'required|string|min:2|max:5',
            'destination'       => 'required|string|min:2|max:5',
            'date'              => 'required|date_format:Y-m-d',
            'train_number'      => 'required|string',
            'grade'             => 'required|string',
            'class'             => 'required|string',
            'adult'             => 'required|integer|min:1|max:7',
            'child'             => 'nullable|integer|min:0|max:7',
            'infant'            => 'nullable|integer|min:0|max:4',
            'price_adult'       => 'required',
            'train_name'        => 'required|string',
            'departure_station' => 'required|string',
            'departure_time'    => 'required|string',
            'arrival_station'   => 'required|string',
            'arrival_time'      => 'required|string',
            'passengers'                       => 'required|array',
            'passengers.adults'                => 'required|array|min:1',
            'passengers.adults.*.name'         => 'required|string|max:60',
            'passengers.adults.*.birthdate'    => 'required|date_format:Y-m-d',
            'passengers.adults.*.phone'        => 'required|string|max:20',
            'passengers.adults.*.id_number'    => 'required|string|max:30',
            'passengers.infants'               => 'nullable|array',
            'passengers.infants.*.name'        => 'required|string|max:60',
            'passengers.infants.*.birthdate'   => 'required|date_format:Y-m-d',
            'passengers.infants.*.id_number'   => 'nullable|string|max:30',
        ]);

        $res = $this->travel->bookTrain([
            'origin'           => $v['origin'],
            'destination'      => $v['destination'],
            'date'             => $v['date'],
            'trainNumber'      => $v['train_number'],
            'grade'            => $v['grade'],
            'class'            => $v['class'],
            'adult'            => $v['adult'],
            'child'            => $v['child']  ?? 0,
            'infant'           => $v['infant'] ?? 0,
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
                    'name'      => $p['name'],
                    'birthdate' => $p['birthdate'],
                    'phone'     => $p['phone'],
                    'idNumber'  => $p['id_number'],
                ], $v['passengers']['adults']),
                'infants' => array_map(fn($p) => [
                    'name'      => $p['name'],
                    'birthdate' => $p['birthdate'],
                    'idNumber'  => $p['id_number'] ?? '',
                ], $v['passengers']['infants'] ?? []),
            ],
        ]);

        return $this->respond($res, $res['data'] ?? []);
    }

    public function changeSeat(Request $request)
    {
        $v = $request->validate([
            'booking_code'        => 'required|string',
            'transaction_id'      => 'required|string',
            'seats'               => 'required|array|min:1',
            'seats.*.wagon_code'  => 'required|string',
            'seats.*.wagon_number'=> 'required',
            'seats.*.row'         => 'required',
            'seats.*.column'      => 'required|string',
        ]);

        $seats = array_map(fn($s) => [
            'wagonCode'   => $s['wagon_code'],
            'wagonNumber' => $s['wagon_number'],
            'row'         => $s['row'],
            'column'      => $s['column'],
        ], $v['seats']);

        $res = $this->travel->changeSeat($v['booking_code'], $v['transaction_id'], $seats);

        // Simpan kursi terpilih ke record lokal (meta.book.seats) → e-tiket PDF
        // menampilkan nomor kursi terbaru (bukan auto-assign awal).
        if (\App\Services\TravelService::isSuccess($res['rc'] ?? null)) {
            $tb = \App\Models\TravelBooking::where('vendor_booking_code', $v['booking_code'])->first();
            if ($tb) {
                $meta = $tb->meta ?? [];
                $meta['book'] = array_merge($meta['book'] ?? [], ['seats' => $seats]);
                $tb->update(['meta' => $meta]);
            }
        }

        return $this->respond($res, $res['data'] ?? []);
    }

    public function cancel(Request $request)
    {
        $v = $request->validate([
            'booking_code'   => 'required|string',
            'transaction_id' => 'required|string',
            'reason'         => 'required|string|max:200',
        ]);

        $res = $this->travel->cancelBook($v['booking_code'], $v['transaction_id'], $v['reason']);
        return $this->respond($res, $res['data'] ?? []);
    }

    /** Cek status transaksi by bookCode. */
    public function status(string $bookCode)
    {
        $res = $this->travel->transactionStatus($bookCode);
        return $this->respond($res, $res['data'] ?? []);
    }

    /* ── PESAWAT (Flight) ───────────────────────────────────────────── */

    /** Daftar bandara (cache 24 jam). */
    public function airports()
    {
        $data = Cache::remember('travel:flight:airports', 86400, function () {
            $res = $this->travel->airports();
            return TravelService::isSuccess($res['rc'] ?? null) ? ($res['data'] ?? []) : null;
        });
        if ($data === null) { Cache::forget('travel:flight:airports'); return response()->json(['success' => false, 'message' => 'Gagal memuat bandara.'], 502); }
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Daftar maskapai (cache 6 jam). */
    public function airlines()
    {
        $data = Cache::remember('travel:flight:airlines', 21600, function () {
            $res = $this->travel->airlines();
            return TravelService::isSuccess($res['rc'] ?? null) ? ($res['data'] ?? []) : null;
        });
        if ($data === null) { Cache::forget('travel:flight:airlines'); return response()->json(['success' => false, 'message' => 'Gagal memuat maskapai.'], 502); }
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Cari penerbangan. Request snake_case dari FE. */
    public function searchFlight(Request $request)
    {
        $v = $request->validate([
            'airline'        => 'required|string',
            'departure'      => 'required|string|size:3',
            'arrival'        => 'required|string|size:3|different:departure',
            'departure_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'return_date'    => 'nullable|date_format:Y-m-d',
            'adult'          => 'required|integer|min:1|max:7',
            'child'          => 'nullable|integer|min:0|max:7',
            'infant'         => 'nullable|integer|min:0|max:4',
        ]);

        $res = $this->travel->searchFlight([
            'airline'       => strtoupper($v['airline']),
            'departure'     => strtoupper($v['departure']),
            'arrival'       => strtoupper($v['arrival']),
            'departureDate' => $v['departure_date'],
            'returnDate'    => $v['return_date'] ?? '',
            'adult'         => $v['adult'],
            'child'         => $v['child'] ?? 0,
            'infant'        => $v['infant'] ?? 0,
        ]);
        return $this->respond($res, $res['data'] ?? []);
    }

    /** Cari penerbangan SEMUA maskapai sekaligus (ala Traveloka). */
    public function searchAllFlight(Request $request)
    {
        $v = $request->validate([
            'departure'      => 'required|string|size:3',
            'arrival'        => 'required|string|size:3|different:departure',
            'departure_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'return_date'    => 'nullable|date_format:Y-m-d',
            'adult'          => 'required|integer|min:1|max:7',
            'child'          => 'nullable|integer|min:0|max:7',
            'infant'         => 'nullable|integer|min:0|max:4',
        ]);

        // Opsi A — cache hasil 45 detik (per rute+tanggal+pax): beberapa HP yang mencari hal
        // sama dalam jendela ini mendapat hasil IDENTIK & mengurangi beban vendor. Harga/kursi
        // tetap dikonfirmasi ulang di langkah fare, jadi staleness singkat ini aman.
        $cacheKey = 'travel:flight:searchall:' . md5(implode('|', [
            strtoupper($v['departure']), strtoupper($v['arrival']), $v['departure_date'],
            $v['return_date'] ?? '', (int) $v['adult'], (int) ($v['child'] ?? 0), (int) ($v['infant'] ?? 0),
        ]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json(['success' => true, 'data' => $cached]);
        }

        try {
            $res = $this->travel->searchAllFlights([
                'departure'     => strtoupper($v['departure']),
                'arrival'       => strtoupper($v['arrival']),
                'departureDate' => $v['departure_date'],
                'returnDate'    => $v['return_date'] ?? '',
                'adult'         => $v['adult'],
                'child'         => $v['child'] ?? 0,
                'infant'        => $v['infant'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            logger()->warning('Flight search-all gagal', [
                'route' => $v['departure'] . '-' . $v['arrival'], 'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Layanan maskapai sedang gangguan. Silakan coba lagi beberapa saat.',
            ], 503);
        }

        // Token/auth vendor gagal → bedakan dari "tidak ada penerbangan" (data kosong).
        if (($res['rc'] ?? null) === 'CONFIG') {
            return response()->json([
                'success' => false,
                'message' => 'Layanan maskapai sedang tidak tersedia (autentikasi vendor). Coba lagi nanti.',
            ], 503);
        }

        $flights = $res['flights'] ?? [];
        // Cache HANYA jika ada hasil — jangan cache kosong/gagal transien (mis. semua maskapai
        // sempat timeout), agar request berikutnya mencoba fresh, bukan menyimpan "kosong" 45 dtk.
        if (!empty($flights)) {
            Cache::put($cacheKey, $flights, 45);
        }
        return response()->json(['success' => true, 'data' => $flights]);
    }

    /** Konfirmasi harga (fare). */
    public function flightFare(Request $request)
    {
        $v = $request->validate([
            'airline'        => 'required|string',
            'departure'      => 'required|string|size:3',
            'arrival'        => 'required|string|size:3',
            'departure_date' => 'required|date_format:Y-m-d',
            'return_date'    => 'nullable|date_format:Y-m-d',
            'adult'          => 'required|integer|min:1|max:7',
            'child'          => 'nullable|integer|min:0|max:7',
            'infant'         => 'nullable|integer|min:0|max:4',
            'seats'          => 'required|array|min:1',
            'seats.*'        => 'required|string',
        ]);

        $res = $this->travel->flightFare([
            'airline'       => strtoupper($v['airline']),
            'departure'     => strtoupper($v['departure']),
            'arrival'       => strtoupper($v['arrival']),
            'departureDate' => $v['departure_date'],
            'returnDate'    => $v['return_date'] ?? '',
            'adult'         => $v['adult'],
            'child'         => $v['child'] ?? 0,
            'infant'        => $v['infant'] ?? 0,
            'seats'         => $v['seats'],
        ]);
        return $this->respond($res, $res['data'] ?? []);
    }

    /** Booking pesawat (auth). */
    public function bookFlight(Request $request)
    {
        $v = $request->validate([
            'airline'        => 'required|string',
            'departure'      => 'required|string|size:3',
            'arrival'        => 'required|string|size:3',
            'departure_date' => 'required|date_format:Y-m-d',
            'return_date'    => 'nullable|date_format:Y-m-d',
            'adult'          => 'required|integer|min:1|max:7',
            'child'          => 'nullable|integer|min:0|max:7',
            'infant'         => 'nullable|integer|min:0|max:4',
            'flights'        => 'required|array|min:1',
            'flights.*'      => 'required|string',
            'passengers'                          => 'required|array',
            'passengers.adults'                   => 'required|array|min:1',
            'passengers.adults.*.title'           => 'required|string|max:5',
            'passengers.adults.*.first_name'      => 'required|string|max:40',
            'passengers.adults.*.last_name'       => 'nullable|string|max:40',
            'passengers.adults.*.birthdate'       => 'required|date_format:Y-m-d',
            'passengers.adults.*.id_number'       => 'required|string|max:30',
            'passengers.adults.*.phone'           => 'required|string|max:20',
            'passengers.adults.*.email'           => 'nullable|email|max:60',
            'passengers.children'                 => 'nullable|array',
            'passengers.infants'                  => 'nullable|array',
        ]);

        $mapPax = fn($p) => [
            'title'     => $p['title'] ?? 'MR',
            'firstName' => $p['first_name'] ?? '',
            'lastName'  => $p['last_name'] ?? '',
            'birthdate' => $p['birthdate'] ?? '',
            'idNumber'  => $p['id_number'] ?? '',
            'phone'     => $p['phone'] ?? '',
            'email'     => $p['email'] ?? '',
        ];

        $res = $this->travel->bookFlight([
            'airline'       => strtoupper($v['airline']),
            'departure'     => strtoupper($v['departure']),
            'arrival'       => strtoupper($v['arrival']),
            'departureDate' => $v['departure_date'],
            'returnDate'    => $v['return_date'] ?? '',
            'adult'         => $v['adult'],
            'child'         => $v['child'] ?? 0,
            'infant'        => $v['infant'] ?? 0,
            'flights'       => $v['flights'],
            'passengers'    => [
                'adults'   => array_map($mapPax, $v['passengers']['adults'] ?? []),
                'children' => array_map($mapPax, $v['passengers']['children'] ?? []),
                'infants'  => array_map($mapPax, $v['passengers']['infants'] ?? []),
            ],
        ]);
        return $this->respond($res, $res['data'] ?? []);
    }

    /* ── PELNI (Kapal Laut) ─────────────────────────────────────────── */

    public function pelniOrigins()
    {
        $data = Cache::remember('travel:pelni:origins', 86400, function () {
            $res = $this->travel->pelniOrigins();
            return TravelService::isSuccess($res['rc'] ?? null) ? ($res['data'] ?? []) : null;
        });
        if ($data === null) { Cache::forget('travel:pelni:origins'); return response()->json(['success' => false, 'message' => 'Gagal memuat pelabuhan.'], 502); }
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function pelniDestinations()
    {
        $data = Cache::remember('travel:pelni:destinations', 86400, function () {
            $res = $this->travel->pelniDestinations();
            return TravelService::isSuccess($res['rc'] ?? null) ? ($res['data'] ?? []) : null;
        });
        if ($data === null) { Cache::forget('travel:pelni:destinations'); return response()->json(['success' => false, 'message' => 'Gagal memuat pelabuhan.'], 502); }
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function searchPelni(Request $request)
    {
        $v = $request->validate([
            'origin'      => 'required|integer',
            'destination' => 'required|integer|different:origin',
            'start_date'  => 'required|date_format:Y-m-d|after_or_equal:today',
            'end_date'    => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);
        $res = $this->travel->searchPelni([
            'origin'      => $v['origin'],
            'destination' => $v['destination'],
            'startDate'   => $v['start_date'],
            'endDate'     => $v['end_date'],
        ]);
        return $this->respond($res, $res['data'] ?? []);
    }

    public function pelniCheckAvailability(Request $request)
    {
        $v = $request->validate([
            'origin'           => 'required|integer',
            'origin_call'      => 'required',
            'destination'      => 'required|integer',
            'destination_call' => 'required',
            'departure_date'   => 'required|string',  // YYYYMMDD
            'ship_number'      => 'required|string',
            'sub_class'        => 'required|string',
            'male'             => 'nullable|integer|min:0',
            'female'           => 'nullable|integer|min:0',
        ]);
        $res = $this->travel->checkAvailabilityPelni([
            'origin'          => $v['origin'],
            'originCall'      => $v['origin_call'],
            'destination'     => $v['destination'],
            'destinationCall' => $v['destination_call'],
            'departureDate'   => $v['departure_date'],
            'shipNumber'      => $v['ship_number'],
            'subClass'        => $v['sub_class'],
            'male'            => $v['male'] ?? 0,
            'female'          => $v['female'] ?? 0,
        ]);
        return $this->respond($res, $res['data'] ?? []);
    }

    /* ── helper ─────────────────────────────────────────────────────── */

    private function respond(array $res, $data)
    {
        $rc = $res['rc'] ?? null;
        if (TravelService::isSuccess($rc)) {
            return response()->json(['success' => true, 'data' => $data]);
        }
        return response()->json([
            'success' => false,
            'rc'      => $rc,
            'message' => $res['rd'] ?? TravelService::userMessage($rc),
        ], 422);
    }
}
