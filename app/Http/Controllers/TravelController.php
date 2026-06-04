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
