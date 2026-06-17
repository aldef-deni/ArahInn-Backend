<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Probe response /flight/search untuk PULANG-PERGI (returnDate diisi).
 * WAJIB dijalankan DI SERVER (IP whitelist 103.76.121.180).
 *
 * Contoh:
 *   php artisan travel:flight-rt-probe CGK SUB 2026-06-25 2026-06-28
 *   php artisan travel:flight-rt-probe CGK SUB 2026-06-25 2026-06-28 --airline=TPQG
 *
 * Tujuan: lihat apakah satu /flight/search dgn returnDate mengembalikan
 * BOTH leg (pergi+pulang dalam satu data[]), atau hanya leg berangkat.
 */
class FlightRtProbe extends Command
{
    protected $signature = 'travel:flight-rt-probe {departure} {arrival} {departDate} {returnDate} {--airline=TPJT} {--adult=1} {--child=0} {--infant=0}';
    protected $description = 'Probe response /flight/search PULANG-PERGI (returnDate) dari API Rajabiller';

    public function handle(): int
    {
        $cfg = config('services.raja_travel');
        $url = rtrim($cfg['prod_url'] ?? '', '/');
        $outletId = (string) ($cfg['prod_outlet_id'] ?? '');
        $pin = (string) ($cfg['prod_pin'] ?? '');
        $timeout = (int) ($cfg['timeout'] ?? 45);

        if (!$url || !$outletId || !$pin) {
            $this->error('Config raja_travel (prod_url/outlet/pin) belum lengkap.');
            return self::FAILURE;
        }

        // 1) sign_in → token
        $this->info("sign_in ke {$url} (outlet {$outletId}) ...");
        $signIn = Http::timeout($timeout)->acceptJson()->asJson()
            ->post("{$url}/app/sign_in", ['outletId' => $outletId, 'pin' => $pin]);
        $token = data_get($signIn->json(), 'data.token') ?? data_get($signIn->json(), 'token');
        $this->line('  rc=' . data_get($signIn->json(), 'rc') . ' balance=' . data_get($signIn->json(), 'data.balance'));
        if (!$token) {
            $this->error('Gagal dapat token. Resp: ' . $signIn->body());
            return self::FAILURE;
        }

        // 2) flight/search DENGAN returnDate (PP)
        $payload = [
            'airline'       => $this->option('airline'),
            'departure'     => $this->argument('departure'),
            'arrival'       => $this->argument('arrival'),
            'departureDate' => $this->argument('departDate'),
            'returnDate'    => $this->argument('returnDate'),   // ← PP
            'isLowestPrice' => true,
            'adult'         => (int) $this->option('adult'),
            'child'         => (int) $this->option('child'),
            'infant'        => (int) $this->option('infant'),
            'token'         => $token,
        ];
        $this->info("\n/flight/search PP {$payload['departure']}→{$payload['arrival']} berangkat {$payload['departureDate']} pulang {$payload['returnDate']} ({$payload['airline']}) ...");
        $res = Http::timeout($timeout)->acceptJson()->asJson()->post("{$url}/flight/search", $payload);
        $json = $res->json();

        $this->line('  rc=' . data_get($json, 'rc') . ' rd=' . data_get($json, 'rd'));
        $data = data_get($json, 'data', []);
        $this->line('  jumlah item data[]: ' . (is_array($data) ? count($data) : 0));

        // Ringkas tiap item: tanggal berangkat tiap kelas → tahu leg pergi vs pulang
        $datesSeen = [];
        foreach ((is_array($data) ? $data : []) as $i => $item) {
            $title   = data_get($item, 'title');
            $transit = data_get($item, 'isTransit') ? 'TRANSIT' : 'direct';
            $classes = data_get($item, 'classes', []);
            $firstCls = is_array($classes) && isset($classes[0][0]) ? $classes[0][0] : (is_array($classes) ? ($classes[0] ?? null) : null);
            $dep = data_get($firstCls, 'departure');
            $arr = data_get($firstCls, 'arrival');
            $depDate = data_get($firstCls, 'departureDate');
            $price = data_get($firstCls, 'price');
            $seat = (string) data_get($firstCls, 'seat');
            if ($depDate) $datesSeen[$depDate] = ($datesSeen[$depDate] ?? 0) + 1;
            $this->line(sprintf('  [%d] %s %s→%s depDate=%s price=%s seat=%s',
                $i, $transit, $dep, $arr, $depDate, $price, mb_substr($seat, 0, 28) . '...'));
        }

        $this->info("\n=== KESIMPULAN ===");
        $this->line('Tanggal berangkat yang muncul di response: ' . json_encode(array_keys($datesSeen)));
        if (isset($datesSeen[$this->argument('departDate')]) && isset($datesSeen[$this->argument('returnDate')])) {
            $this->line('✅ Response memuat KEDUA leg (pergi + pulang) → PP via 1x search (filter by departureDate).');
        } elseif (isset($datesSeen[$this->argument('departDate')])) {
            $this->line('ℹ️ Response HANYA leg berangkat → leg pulang perlu search terpisah (swap bandara, date=returnDate).');
        } else {
            $this->line('⚠️ Tidak terbaca jelas — tempel output mentah di bawah ke developer.');
            $this->line(mb_substr($res->body(), 0, 1500));
        }

        return self::SUCCESS;
    }
}
