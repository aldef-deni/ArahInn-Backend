<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TravelService — integrasi Rajabiller Travel API LANGSUNG (bukan XAS webview).
 *
 * Auth: JWT token via POST /app/sign_in {outletId, pin}. Token valid 1 hari,
 * dikirim di BODY tiap request. TIDAK ada signature/hash.
 *
 * Fase 1: KERETA (productCode "WKAI").
 * Flow: station → search → book → [get-seat-layout → change_seat] → payment.
 *
 * Doc: docs/rajabiller-travel-kai-api.md
 *
 * Response codes (pola sama PPOB): 00=sukses, 33=tidak ditemukan, dll.
 */
class TravelService
{
    public const PRODUCT_KERETA = 'WKAI';

    /** Channel: KAI di devel, pesawat/pelni di production. */
    public const CH_KAI  = 'kai';   // kereta — DEVEL
    public const CH_PROD = 'prod';  // pesawat & pelni — PRODUCTION

    private const TOKEN_TTL_SEC = 23 * 3600; // token valid 1 hari, refresh 23 jam

    private int $timeout;
    /** @var array<string,array{url:string,outletId:string,pin:string}> */
    private array $env;

    public function __construct()
    {
        $cfg = config('services.raja_travel');
        $this->timeout = (int) ($cfg['timeout'] ?? 45);
        $this->env = [
            self::CH_KAI => [
                'url'      => rtrim($cfg['kai_url'] ?? '', '/'),
                'outletId' => (string) ($cfg['kai_outlet_id'] ?? ''),
                'pin'      => (string) ($cfg['kai_pin'] ?? ''),
            ],
            self::CH_PROD => [
                'url'      => rtrim($cfg['prod_url'] ?? '', '/'),
                'outletId' => (string) ($cfg['prod_outlet_id'] ?? ''),
                'pin'      => (string) ($cfg['prod_pin'] ?? ''),
            ],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * AUTH
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Ambil JWT token untuk channel (cache harian per channel).
     */
    public function getToken(string $channel, bool $forceRefresh = false): ?string
    {
        $key = "raja_travel:token:{$channel}";
        if (!$forceRefresh) {
            $cached = Cache::get($key);
            if ($cached) return $cached;
        }

        $res = $this->signIn($channel);
        $token = $res['token'] ?? null;
        if ($token) {
            Cache::put($key, $token, self::TOKEN_TTL_SEC);
        }
        return $token;
    }

    /**
     * POST /app/sign_in — dapat token + balance untuk channel tertentu.
     * @return array { rc, rd, token, balance, _http_status }
     */
    public function signIn(string $channel): array
    {
        $e = $this->env[$channel];
        $res = $this->raw($channel, '/app/sign_in', [
            'outletId' => $e['outletId'],
            'pin'      => $e['pin'],
        ], withToken: false);

        return [
            'rc'           => $res['rc']   ?? null,
            'rd'           => $res['rd']   ?? null,
            'token'        => $res['token'] ?? null,
            'balance'      => $res['data']['balance'] ?? null,
            '_http_status' => $res['_http_status'] ?? 0,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * KERETA — FLOW
     * ────────────────────────────────────────────────────────────────── */

    /** POST /train/station — daftar stasiun. */
    public function stations(): array
    {
        return $this->authed(self::CH_KAI, '/train/station', []);
    }

    /**
     * POST /train/search — cari jadwal kereta.
     * @param array $p { origin, destination, date(YYYY-MM-DD), adult, infant }
     */
    public function searchTrain(array $p): array
    {
        return $this->authed(self::CH_KAI, '/train/search', [
            'productCode' => self::PRODUCT_KERETA,
            'origin'      => $p['origin'],
            'destination' => $p['destination'],
            'date'        => $p['date'],
            'adult'       => (string) ($p['adult']  ?? 1),
            'infant'      => (string) ($p['infant'] ?? 0),
        ]);
    }

    /**
     * POST /train/book — booking kereta (belum bayar).
     * @param array $p semua field jadwal + passengers{adults[],infants[]}
     *   Wajib: origin, destination, date, trainNumber, grade, class,
     *          adult, child, infant, priceAdult, priceChild, priceInfant,
     *          trainName, departureStation, departureTime, arrivalStation,
     *          arrivalTime, passengers
     * @return array + data{ bookingCode, transactionId, seats[], nominalAdmin,
     *                        normalSales, extraFee, discount, timeLimit }
     */
    public function bookTrain(array $p): array
    {
        $payload = array_merge([
            'productCode' => self::PRODUCT_KERETA,
        ], $p);

        return $this->authed(self::CH_KAI, '/train/book', $payload);
    }

    /**
     * POST /train/get-seat-layout — denah kursi gerbong.
     * @param array $p { origin, destination, date, trainNumber }
     */
    public function seatLayout(array $p): array
    {
        return $this->authed(self::CH_KAI, '/train/get-seat-layout', [
            'productCode' => self::PRODUCT_KERETA,
            'origin'      => $p['origin'],
            'destination' => $p['destination'],
            'date'        => $p['date'],
            'trainNumber' => $p['trainNumber'],
        ]);
    }

    /**
     * POST /train/change_seat — ganti kursi (versi recommended dgn wagon per-seat).
     * @param string $bookingCode
     * @param string $transactionId
     * @param array  $seats  [{ wagonCode, wagonNumber, row, column }, ...]
     */
    public function changeSeat(string $bookingCode, string $transactionId, array $seats): array
    {
        return $this->authed(self::CH_KAI, '/train/change_seat', [
            'productCode'   => self::PRODUCT_KERETA,
            'bookingCode'   => $bookingCode,
            'transactionId' => $transactionId,
            'seats'         => $seats,
        ]);
    }

    /** POST /train/cancel_book — batalkan booking yang belum dibayar. */
    public function cancelBook(string $bookingCode, string $transactionId, string $reason): array
    {
        return $this->authed(self::CH_KAI, '/train/cancel_book', [
            'productCode'   => self::PRODUCT_KERETA,
            'bookingCode'   => $bookingCode,
            'transactionId' => $transactionId,
            'reason'        => $reason,
        ]);
    }

    /**
     * POST /train/payment — bayar (potong saldo deposit Rajabiller) + issue tiket.
     * @return array + data{ transaction_id, url_etiket, url_image, url_struk, komisi }
     */
    public function payTrain(string $bookingCode, string $transactionId, array $money): array
    {
        return $this->authed(self::CH_KAI, '/train/payment', [
            'productCode'   => self::PRODUCT_KERETA,
            'bookingCode'   => $bookingCode,
            'transactionId' => $transactionId,
            'nominal'       => $money['nominal'],
            'nominal_admin' => $money['nominal_admin'] ?? 0,
            'discount'      => $money['discount'] ?? 0,
            'pay_type'      => 'TUNAI',
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * PESAWAT — FLOW (channel PRODUCTION)
     * airport → configuration → search → fare → book → payment
     * Doc: docs/rajabiller-travel-pesawat-api.md
     * ────────────────────────────────────────────────────────────────── */

    /** Daftar bandara. */
    public function airports(): array
    {
        return $this->authed(self::CH_PROD, '/flight/airport', []);
    }

    /** Daftar maskapai (configuration). */
    public function airlines(): array
    {
        return $this->authed(self::CH_PROD, '/flight/configuration', []);
    }

    /**
     * Cari penerbangan.
     * @param array $p { airline, departure, arrival, departureDate, returnDate?, adult, child, infant }
     */
    public function searchFlight(array $p): array
    {
        return $this->authed(self::CH_PROD, '/flight/search', [
            'airline'        => $p['airline'],
            'departure'      => $p['departure'],
            'arrival'        => $p['arrival'],
            'departureDate'  => $p['departureDate'],
            'returnDate'     => $p['returnDate'] ?? '',
            'isLowestPrice'  => $p['isLowestPrice'] ?? true,
            'adult'          => (int) ($p['adult']  ?? 1),
            'child'          => (int) ($p['child']  ?? 0),
            'infant'         => (int) ($p['infant'] ?? 0),
        ]);
    }

    /** Maskapai domestik default untuk search semua maskapai. */
    public const AIRLINES = ['TPGA', 'TPQG', 'TPJT', 'TPIW', 'TPID', 'TPSJ', 'TPIN', 'TPQZ'];
    public const AIRLINE_NAMES = [
        'TPGA' => 'Garuda Indonesia', 'TPQG' => 'Citilink', 'TPJT' => 'Lion Air',
        'TPIW' => 'Wings Air', 'TPID' => 'Batik Air', 'TPSJ' => 'Sriwijaya Air',
        'TPIN' => 'NAM Air', 'TPQZ' => 'Indonesia AirAsia',
    ];

    /**
     * Cari penerbangan dari SEMUA maskapai sekaligus (paralel via Http::pool).
     * @param array $p { departure, arrival, departureDate, returnDate?, adult, child, infant }
     * @return array { rc, flights: [ ...flight + airline + airlineName ] }
     */
    public function searchAllFlights(array $p): array
    {
        $token = $this->getToken(self::CH_PROD);
        if (!$token) {
            return ['rc' => 'CONFIG', 'flights' => []];
        }
        $baseUrl = $this->env[self::CH_PROD]['url'];
        $payload = [
            'departure'     => $p['departure'],
            'arrival'       => $p['arrival'],
            'departureDate' => $p['departureDate'],
            'returnDate'    => $p['returnDate'] ?? '',
            'isLowestPrice' => true,
            'adult'         => (int) ($p['adult'] ?? 1),
            'child'         => (int) ($p['child'] ?? 0),
            'infant'        => (int) ($p['infant'] ?? 0),
            'token'         => $token,
        ];

        $responses = Http::pool(fn ($pool) => array_map(
            fn ($a) => $pool->as($a)->timeout($this->timeout)->acceptJson()->asJson()
                ->post($baseUrl . '/flight/search', $payload + ['airline' => $a]),
            self::AIRLINES
        ));

        $flights = [];
        foreach (self::AIRLINES as $a) {
            $resp = $responses[$a] ?? null;
            try {
                if (!$resp || !$resp->ok()) continue;
                $data = $resp->json()['data'] ?? [];
            } catch (\Throwable $e) { continue; }
            if (!is_array($data)) continue;
            foreach ($data as $fl) {
                $fl['airline']     = $a;
                $fl['airlineName'] = self::AIRLINE_NAMES[$a] ?? $a;
                $flights[] = $fl;
            }
        }

        return ['rc' => '00', 'flights' => $flights];
    }

    /**
     * Konfirmasi harga (fare).
     * @param array $p { airline, departure, arrival, departureDate, returnDate?, adult, child, infant, seats:[<seat string>] }
     */
    public function flightFare(array $p): array
    {
        return $this->authed(self::CH_PROD, '/flight/fare', [
            'airline'       => $p['airline'],
            'departure'     => $p['departure'],
            'arrival'       => $p['arrival'],
            'departureDate' => $p['departureDate'],
            'returnDate'    => $p['returnDate'] ?? '',
            'adult'         => (string) ($p['adult']  ?? 1),
            'child'         => (int) ($p['child']  ?? 0),
            'infant'        => (int) ($p['infant'] ?? 0),
            'seats'         => $p['seats'],
        ]);
    }

    /**
     * Booking pesawat. Passenger di-encode jadi string delimited.
     * @param array $p { airline, departure, arrival, departureDate, returnDate?, adult, child, infant,
     *                   flights:[<seat string>],
     *                   passengers:{ adults:[{title,firstName,lastName,birthdate,idNumber,phone,email}],
     *                                children:[{...}], infants:[{title,firstName,lastName,birthdate,idNumber}] } }
     */
    public function bookFlight(array $p): array
    {
        $pax = $p['passengers'] ?? [];
        return $this->authed(self::CH_PROD, '/flight/book', [
            'airline'       => $p['airline'],
            'departure'     => $p['departure'],
            'arrival'       => $p['arrival'],
            'departureDate' => $p['departureDate'],
            'returnDate'    => $p['returnDate'] ?? '',
            'adult'         => (string) ($p['adult']  ?? 1),
            'child'         => (int) ($p['child']  ?? 0),
            'infant'        => (int) ($p['infant'] ?? 0),
            'flights'       => $p['flights'],
            'passengers'    => [
                'adults'   => array_map([self::class, 'encodeAdult'],  $pax['adults']   ?? []),
                'children' => array_map([self::class, 'encodeChild'],  $pax['children'] ?? []),
                'infants'  => array_map([self::class, 'encodeInfant'], $pax['infants']  ?? []),
            ],
        ]);
    }

    /**
     * Bayar/issue pesawat.
     * @param array $p { airline, transactionId, bookingCode, paymentCode?, simulate? }
     */
    public function payFlight(array $p): array
    {
        $payload = [
            'airline'       => $p['airline'],
            'transactionId' => $p['transactionId'],
            'bookingCode'   => $p['bookingCode'],
            'paymentCode'   => $p['paymentCode'] ?? '',
        ];
        // simulateSuccess=yes → mode simulasi (tidak potong saldo asli). Hapus untuk production asli.
        if (!empty($p['simulate'])) {
            $payload['simulateSuccess'] = 'yes';
        }
        return $this->authed(self::CH_PROD, '/flight/payment', $payload);
    }

    public function flightBookingInfo(string $airline, string $departure, string $arrival, string $transactionId): array
    {
        return $this->authed(self::CH_PROD, '/flight/booking_info', [
            'airline'       => $airline,
            'departure'     => $departure,
            'arrival'       => $arrival,
            'transactionId' => $transactionId,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * PELNI (Kapal Laut) — channel PRODUCTION
     * get_origin → get_destination → search → check_availability → book → payment
     * Doc: docs/rajabiller-travel-pelni-api.md
     * ────────────────────────────────────────────────────────────────── */

    public function pelniOrigins(): array      { return $this->authed(self::CH_PROD, '/pelni/get_origin', []); }
    public function pelniDestinations(): array  { return $this->authed(self::CH_PROD, '/pelni/get_destination', []); }

    /** @param array $p { origin, destination, startDate(YYYY-MM-DD), endDate(YYYY-MM-DD) } */
    public function searchPelni(array $p): array
    {
        return $this->authed(self::CH_PROD, '/pelni/search', [
            'origin'      => (int) $p['origin'],
            'destination' => (int) $p['destination'],
            'startDate'   => $p['startDate'],
            'endDate'     => $p['endDate'],
        ]);
    }

    /** @param array $p { origin, originCall, destination, destinationCall, departureDate(YYYYMMDD), shipNumber, subClass, male, female } */
    public function checkAvailabilityPelni(array $p): array
    {
        return $this->authed(self::CH_PROD, '/pelni/check_availability', [
            'origin'          => (int) $p['origin'],
            'originCall'      => $p['originCall'],
            'destination'     => (int) $p['destination'],
            'destinationCall' => $p['destinationCall'],
            'departureDate'   => $p['departureDate'],
            'shipNumber'      => $p['shipNumber'],
            'subClass'        => $p['subClass'],
            'male'            => (int) ($p['male'] ?? 0),
            'female'          => (int) ($p['female'] ?? 0),
        ]);
    }

    /**
     * @param array $p semua field kapal + harga + contact + passengers{adults[{name,birthDate,identityNumber,gender}],children,infants}
     */
    public function bookPelni(array $p): array
    {
        return $this->authed(self::CH_PROD, '/pelni/book', [
            'harga_dewasa'     => (string) ($p['hargaDewasa'] ?? '0'),
            'harga_anak'       => (string) ($p['hargaAnak'] ?? '0'),
            'harga_infant'     => (string) ($p['hargaInfant'] ?? '0'),
            'pelabuhan_asal'   => $p['pelabuhanAsal'] ?? '',
            'pelabuhan_tujuan' => $p['pelabuhanTujuan'] ?? '',
            'shipName'         => $p['shipName'] ?? '',
            'origin'           => (int) $p['origin'],
            'originCall'       => $p['originCall'],
            'destination'      => (int) $p['destination'],
            'destinationCall'  => $p['destinationCall'],
            'departureDate'    => $p['departureDate'],
            'shipNumber'       => $p['shipNumber'],
            'subClass'         => $p['subClass'],
            'male'             => (int) ($p['male'] ?? 0),
            'female'           => (int) ($p['female'] ?? 0),
            'adult'            => (int) ($p['adult'] ?? 1),
            'child'            => (int) ($p['child'] ?? 0),
            'infant'           => (int) ($p['infant'] ?? 0),
            'isFamily'         => $p['isFamily'] ?? 'N',
            'contact'          => [
                'email' => $p['contact']['email'] ?? '',
                'phone' => $p['contact']['phone'] ?? '',
            ],
            'passengers'       => [
                'adults'   => array_map([self::class, 'pelniPax'], $p['passengers']['adults']   ?? []),
                'children' => array_map([self::class, 'pelniPax'], $p['passengers']['children'] ?? []),
                'infants'  => array_map([self::class, 'pelniPax'], $p['passengers']['infants']  ?? []),
            ],
        ]);
    }

    /** Penumpang Pelni: { name, birthDate(Y-m-d), identityNumber, gender(M/F) } */
    private static function pelniPax(array $p): array
    {
        return [
            'name'           => $p['name'] ?? '',
            'birthDate'      => $p['birthDate'] ?? ($p['birthdate'] ?? ''),
            'identityNumber' => $p['identityNumber'] ?? ($p['idNumber'] ?? ''),
            'gender'         => $p['gender'] ?? 'M',
        ];
    }

    /** @param array $p { paymentCode, transactionId, simulate? } */
    public function payPelni(array $p): array
    {
        $payload = [
            'paymentCode'   => $p['paymentCode'],
            'transactionId' => $p['transactionId'],
        ];
        if (!empty($p['simulate'])) {
            $payload['simulateSuccess'] = 'yes';
        }
        return $this->authed(self::CH_PROD, '/pelni/payment', $payload);
    }

    /* ── Passenger string encoders (format Rajabiller, lihat doc) ──────── */

    /** Tanggal Y-m-d → m/d/Y (format yang dipakai Rajabiller flight). */
    private static function dobMDY(string $ymd): string
    {
        $parts = explode('-', $ymd);
        return count($parts) === 3 ? "{$parts[1]}/{$parts[2]}/{$parts[0]}" : $ymd;
    }

    /** Punya paspor → penumpang internasional (WNA atau WNI ke luar negeri). */
    private static function hasPassport(array $p): bool
    {
        return !empty($p['passportNumber']);
    }

    // Mapping Rajabiller posisi 13-18 (setelah email): DOCTYPE;nationality;passportNationality;
    // passportExpiry;passportIssued;passportIssuing;baggage. Domestik pakai DOCTYPE 'KTP'/'1' &
    // NIK di pos 6; internasional pakai 'PASSPORT' & nomor paspor di pos 6 + detail paspor terisi.
    private static function paxTail(array $p): array
    {
        if (self::hasPassport($p)) {
            return [
                'PASSPORT',
                $p['nationality'] ?? 'ID',
                $p['nationality'] ?? 'ID',
                self::dobMDY($p['passportExpiry'] ?? ''),
                self::dobMDY($p['passportIssueDate'] ?? ''),
                $p['passportIssuingCountry'] ?? 'ID',
                '',
            ];
        }
        // Domestik: format produksi yang sudah berhasil (pos 12 = '1'). JANGAN diubah.
        return ['1', 'ID', 'ID', '', '', 'ID', ''];
    }

    /** Dokumen di pos 6: paspor bila internasional, selain itu NIK. */
    private static function paxDocNo(array $p): string
    {
        return self::hasPassport($p) ? ($p['passportNumber'] ?? '') : ($p['idNumber'] ?? '');
    }

    /** ADT;title;first;last;MM/DD/YYYY;[NIK|paspor];::phone;::phone;;;;email;[KTP|PASSPORT];nat;passNat;passExp;passIssued;passIssuing;bag */
    private static function encodeAdult(array $p): string
    {
        $phone = $p['phone'] ?? '';
        return implode(';', array_merge([
            'ADT', $p['title'] ?? 'MR', $p['firstName'] ?? '', $p['lastName'] ?? '',
            self::dobMDY($p['birthdate'] ?? ''), self::paxDocNo($p),
            "::{$phone}", "::{$phone}", '', '', '', $p['email'] ?? '',
        ], self::paxTail($p)));
    }

    /** CHD;title;first;last;MM/DD/YYYY;NIK;ID;ID;;;ID; (domestik; intl anak menyusul setelah verifikasi vendor) */
    private static function encodeChild(array $p): string
    {
        return implode(';', [
            'CHD', $p['title'] ?? 'MSTR', $p['firstName'] ?? '', $p['lastName'] ?? '',
            self::dobMDY($p['birthdate'] ?? ''), self::paxDocNo($p),
            'ID', 'ID', '', '', 'ID', '',
        ]);
    }

    /** INF;title;first;last;MM/DD/YYYY;NIK;ID;ID;;;ID; (domestik; intl bayi menyusul setelah verifikasi vendor) */
    private static function encodeInfant(array $p): string
    {
        return implode(';', [
            'INF', $p['title'] ?? 'MSTR', $p['firstName'] ?? '', $p['lastName'] ?? '',
            self::dobMDY($p['birthdate'] ?? ''), self::paxDocNo($p),
            'ID', 'ID', '', '', 'ID', '',
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * GENERAL — cek transaksi
     * ────────────────────────────────────────────────────────────────── */

    /** Channel transaksi berdasar produk: KERETA→devel, pesawat/pelni→prod. */
    private function channelForProduct(string $product): string
    {
        return strtoupper($product) === 'KERETA' ? self::CH_KAI : self::CH_PROD;
    }

    public function transactionInfo(string $transactionId, string $product = 'KERETA'): array
    {
        return $this->authed($this->channelForProduct($product), '/app/transaction_info', [
            'product'        => $product,
            'transaction_id' => $transactionId,
        ]);
    }

    public function transactionStatus(string $bookCode, string $product = 'KERETA'): array
    {
        return $this->authed($this->channelForProduct($product), '/app/transaction_status', [
            'product'  => $product,
            'bookCode' => $bookCode,
        ]);
    }

    public function transactionList(string $product = 'KERETA'): array
    {
        return $this->authed($this->channelForProduct($product), '/app/transaction_list', [
            'product' => $product,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * HTTP CORE
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Panggil endpoint dengan token (inject otomatis) untuk channel tertentu.
     * Retry sekali kalau token expired.
     */
    private function authed(string $channel, string $path, array $payload): array
    {
        $e = $this->env[$channel] ?? null;
        if (!$e || empty($e['outletId']) || empty($e['pin'])) {
            Log::warning("TravelService: credential channel {$channel} belum di-set");
            return ['rc' => 'CONFIG', 'rd' => 'Travel belum dikonfigurasi. Hubungi admin.', '_http_status' => 0];
        }

        $token = $this->getToken($channel);
        $res   = $this->raw($channel, $path, array_merge($payload, ['token' => $token]));

        // Token expired/invalid → refresh sekali lalu retry
        if ($this->isAuthError($res['rc'] ?? null, $res['_http_status'] ?? 0)) {
            $token = $this->getToken($channel, forceRefresh: true);
            if ($token) {
                $res = $this->raw($channel, $path, array_merge($payload, ['token' => $token]));
            }
        }

        return $res;
    }

    /**
     * Raw HTTP POST JSON ke Rajabiller Travel (base URL per channel).
     * @return array decoded json + _http_status (+ _duration_ms)
     */
    private function raw(string $channel, string $path, array $payload, bool $withToken = true): array
    {
        $baseUrl = $this->env[$channel]['url'] ?? '';
        $start = microtime(true);
        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl . $path, $payload);

            $duration = (int) round((microtime(true) - $start) * 1000);
            $data     = $response->json() ?: [];

            Log::info('Travel API request', [
                'channel'     => $channel,
                'path'        => $path,
                'http_status' => $response->status(),
                'rc'          => $data['rc'] ?? null,
                'duration_ms' => $duration,
            ]);

            $data['_http_status'] = $response->status();
            $data['_duration_ms'] = $duration;
            return $data;
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $start) * 1000);
            Log::error('Travel API request failed', [
                'path'    => $path,
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            return [
                'rc'           => 'ERR',
                'rd'           => 'Koneksi ke vendor gagal. Coba lagi nanti.',
                '_http_status' => 0,
                '_duration_ms' => $duration,
            ];
        }
    }

    /** Heuristik: apakah response menandakan token expired/invalid. */
    private function isAuthError(?string $rc, int $httpStatus): bool
    {
        if ($httpStatus === 401 || $httpStatus === 403) return true;
        // RC khusus token (sesuaikan kalau doc kasih kode pasti)
        return in_array($rc, ['96', '99', 'TOKEN', 'TOKEN_EXPIRED'], true);
    }

    /* ──────────────────────────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────────────────────────── */

    public static function isSuccess(?string $rc): bool { return $rc === '00'; }

    public static function userMessage(?string $rc): string
    {
        return match ($rc) {
            '00'     => 'Sukses',
            '33'     => 'Data tidak ditemukan.',
            '01'     => 'Kredensial salah. Hubungi admin.',
            '06'     => 'Saldo deposit tidak cukup. Hubungi admin.',
            '16'     => 'Transaksi gagal. Coba lagi.',
            '68'     => 'Transaksi sedang diproses.',
            'CONFIG' => 'Layanan tiket belum tersedia.',
            'ERR'    => 'Koneksi ke vendor gagal.',
            default  => 'Terjadi kesalahan, coba lagi nanti.',
        };
    }
}
