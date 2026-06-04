<?php

namespace App\Console\Commands;

use App\Models\PpobCategory;
use App\Models\PpobProduct;
use App\Services\RajaBillerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sync product catalog dari Rajabiller method 'info'.
 *
 * Mapping group → UI category (sesuai struktur tab di Home ArahInn):
 *   - Pulsa & Data : TELKOMSEL, ISAT, KARTU3, SMART, AXIS / XL, BOLT, FREN
 *   - Listrik PLN  : PLN (prabayar + pascabayar)
 *   - E-Wallet     : EMONEY
 *   - Bayar Tagihan: PDAM, TELKOM, TV BERLANGGANAN, MULTI FINANCE, ASURANSI,
 *                    KARTU KREDIT, TELEPON PASCA BAYAR, PAJAK, SAMSAT, IPL, EDUKASI
 *   - Game Online  : GAME ONLINE, GAME ONLINE IN
 *
 * Usage:
 *   php artisan ppob:sync-catalog                    # all groups
 *   php artisan ppob:sync-catalog --group=TELKOMSEL  # specific group
 *   php artisan ppob:sync-catalog --markup-pct=2     # default markup 2%
 */
class SyncPpobCatalog extends Command
{
    protected $signature = 'ppob:sync-catalog
        {--group= : Spesifik group (TELKOMSEL/PLN/PDAM/dll). Kosong = sync semua.}
        {--markup-pct=2 : Default markup percent untuk PREPAID (default 2%)}
        {--markup-min=500 : Minimum markup Rupiah untuk PREPAID}';

    protected $description = 'Sync PPOB product catalog dari Rajabiller method info.';

    /**
     * Mapping group Rajabiller → UI category code.
     */
    private const GROUP_TO_CATEGORY = [
        'TELKOMSEL'           => 'pulsa-data',
        'ISAT'                => 'pulsa-data',
        'AXIS / XL'           => 'pulsa-data',
        'KARTU3'              => 'pulsa-data',
        'SMART'               => 'pulsa-data',
        'FREN'                => 'pulsa-data',
        'PLN'                 => 'pln',
        'EMONEY'              => 'ewallet',
        'PDAM'                => 'tagihan',
        'TELKOM'              => 'tagihan',
        'TV BERLANGGANAN'     => 'tagihan',
        'MULTI FINANCE'       => 'tagihan',
        'ASURANSI'            => 'tagihan',
        'KARTU KREDIT'        => 'tagihan',
        'TELEPON PASCA BAYAR' => 'tagihan',
        'PAJAK'               => 'tagihan',
        'SAMSAT'              => 'tagihan',
        'GAME ONLINE'         => 'game',
        'GAME ONLINE IN'      => 'game',
    ];

    private RajaBillerService $svc;

    public function handle(RajaBillerService $svc): int
    {
        $this->svc = $svc;

        $group     = $this->option('group');
        $markupPct = (float) $this->option('markup-pct');
        $markupMin = (float) $this->option('markup-min');

        $groups = $group ? [strtoupper($group)] : array_keys(self::GROUP_TO_CATEGORY);

        $stats = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($groups as $g) {
            $this->info("┌─ Syncing group: {$g}");

            $resp = $svc->info($g);

            if (!RajaBillerService::isSuccess($resp['rc'] ?? null)) {
                $this->error("│ ✗ Fetch failed: rc={$resp['rc']} status={$resp['status']}");
                $stats['failed']++;
                continue;
            }

            $items = $resp['data'] ?? [];
            $stats['fetched'] += count($items);

            $this->info("│ ✓ Fetched " . count($items) . " items");

            $categoryId = $this->resolveCategoryId($g);
            if (!$categoryId) {
                $this->warn("│ ⚠ Category mapping missing for group {$g} — skipped");
                $stats['skipped'] += count($items);
                continue;
            }

            foreach ($items as $item) {
                $result = $this->upsertProduct($item, $categoryId, $g, $markupPct, $markupMin);
                $stats[$result]++;
            }

            $this->info("└─ Group {$g} done\n");
        }

        $this->newLine();
        $this->info("┌─────────────────────────────────────────┐");
        $this->info("│  Sync summary                           │");
        $this->info("├─────────────────────────────────────────┤");
        $this->info("│  Total fetched : " . str_pad($stats['fetched'], 22, ' ', STR_PAD_RIGHT) . "│");
        $this->info("│  Created       : " . str_pad($stats['created'], 22, ' ', STR_PAD_RIGHT) . "│");
        $this->info("│  Updated       : " . str_pad($stats['updated'], 22, ' ', STR_PAD_RIGHT) . "│");
        $this->info("│  Skipped       : " . str_pad($stats['skipped'], 22, ' ', STR_PAD_RIGHT) . "│");
        $this->info("│  Failed groups : " . str_pad($stats['failed'], 22, ' ', STR_PAD_RIGHT) . "│");
        $this->info("└─────────────────────────────────────────┘");

        return self::SUCCESS;
    }

    private function resolveCategoryId(string $group): ?int
    {
        $categoryCode = self::GROUP_TO_CATEGORY[$group] ?? null;
        if (!$categoryCode) return null;

        $cat = PpobCategory::firstOrCreate(
            ['code' => $categoryCode],
            [
                'name'       => $this->categoryName($categoryCode),
                'group'      => $categoryCode,
                'type'       => $this->categoryType($categoryCode, $group),
                'is_active'  => true,
                'sort_order' => $this->categorySortOrder($categoryCode),
            ]
        );

        return $cat->id;
    }

    private function upsertProduct(array $item, int $categoryId, string $group, float $markupPct, float $markupMin): string
    {
        $code      = $item['id_produk']   ?? null;
        $name      = $item['nama_produk'] ?? $code;
        // Rajabiller field name: kadang `harga`, kadang `harga_jual` — handle keduanya.
        $hargaJual = (float) ($item['harga'] ?? $item['harga_jual'] ?? 0);
        $admin     = (float) ($item['admin']      ?? 0);
        $komisi    = (float) ($item['komisi']     ?? 0);
        $statusLbl = $item['status'] ?? 'AKTIF';

        if (!$code) return 'skipped';

        // Normalize status_label → internal status
        $status = match (true) {
            str_contains($statusLbl, 'CLOSE')         => 'inactive',
            str_contains($statusLbl, 'GANGGUAN')      => 'gangguan',
            default                                    => 'active',
        };

        // Hide produk yang TIDAK reguler customer-facing.
        // NOTE: filter CONSERVATIVE — kalau ragu lebih baik biarkan active, admin manual hide via DB.
        //   - Code suffix Z       → produk promo tentatif (sering reject di production)
        //   - Code mengandung H2H → host-to-host (channel internal, beda dari name "H2H")
        //   - Name "NON TAGLIST"  → variant khusus admin
        //   - Name "ADMIN ####"   → variant biaya admin (2500/3000)
        // SKIP: filter by name "H2H" terlalu aggressive — kadang produk granted di production
        //       punya nama mengandung "H2H" tapi sebenarnya channel reguler (mis. PLNPASCH).
        $upperCode = strtoupper($code);
        $upperName = strtoupper($name);
        $isPromoVariant = (
            preg_match('/\dZ$/', $upperCode) ||            // mis. S5Z, S10Z, T20Z
            str_contains($upperCode, 'H2H') ||             // code-level H2H channel
            str_contains($upperName, 'NON TAGLIST') ||
            preg_match('/ADMIN\s*\d{3,4}/', $upperName)    // ADMIN 2500, ADMIN 3000
        );
        if ($isPromoVariant && $status === 'active') {
            $status = 'inactive';
        }

        // Calculate selling price
        $isPrepaid = !in_array($group, ['PDAM', 'TELKOM', 'TV BERLANGGANAN', 'MULTI FINANCE',
            'ASURANSI', 'KARTU KREDIT', 'TELEPON PASCA BAYAR', 'PAJAK', 'SAMSAT',
            'GAME ONLINE IN'], true);

        if ($isPrepaid) {
            // PREPAID: harga_jual = harga beli, tambah markup
            $markup    = max($markupMin, $hargaJual * ($markupPct / 100));
            $priceBuy  = $hargaJual;
            $priceSell = $hargaJual + $markup;
        } else {
            // POSTPAID: harga_jual biasanya 0, tagihan dari inquiry. Admin & komisi dari Rajabiller.
            $priceBuy  = 0; // akan di-set saat inquiry
            $priceSell = 0; // akan di-set saat inquiry (tagihan + admin)
        }

        $existing = PpobProduct::where('raja_biller_code', $code)->first();
        $isUpdate = (bool) $existing;

        PpobProduct::updateOrCreate(
            ['raja_biller_code' => $code],
            [
                'category_id'    => $categoryId,
                'name'           => $name,
                'operator'       => $this->extractOperator($group, $name),
                'nominal'        => $this->extractNominal($name) ?? 0,
                'price_buy'      => $priceBuy,
                'price_sell'     => $priceSell,
                'admin_fee'      => $admin,
                'komisi'         => $komisi,
                'status'         => $status,
                'status_label'   => $statusLbl,
                'meta'           => $item,
                'synced_at'      => now(),
                'last_synced_at' => now(),
            ]
        );

        return $isUpdate ? 'updated' : 'created';
    }

    private function categoryName(string $code): string
    {
        return match ($code) {
            'pulsa-data' => 'Pulsa & Data',
            'pln'        => 'Listrik PLN',
            'ewallet'    => 'E-Wallet',
            'tagihan'    => 'Bayar Tagihan',
            'game'       => 'Game Online',
            default      => ucfirst($code),
        };
    }

    private function categoryType(string $code, string $group): string
    {
        // Hampir semua PREPAID kecuali tagihan-tagihan
        return in_array($group, ['PDAM', 'TELKOM', 'TV BERLANGGANAN', 'MULTI FINANCE',
            'ASURANSI', 'KARTU KREDIT', 'TELEPON PASCA BAYAR', 'PAJAK', 'SAMSAT',
            'GAME ONLINE IN'], true)
            ? 'pascabayar'
            : 'prabayar';
    }

    private function categorySortOrder(string $code): int
    {
        return match ($code) {
            'pulsa-data' => 1,
            'pln'        => 2,
            'tagihan'    => 3,
            'ewallet'    => 4,
            'game'       => 5,
            default      => 99,
        };
    }

    private function extractOperator(string $group, string $name): string
    {
        return match ($group) {
            'TELKOMSEL'       => 'Telkomsel',
            'ISAT'            => 'Indosat',
            'AXIS / XL'       => str_contains($name, 'AXIS') ? 'Axis' : 'XL',
            'KARTU3'          => 'Tri',
            'SMART'           => 'Smartfren',
            'BOLT'            => 'Bolt',
            'FREN'            => 'Fren',
            'PLN'             => 'PLN',
            'PDAM'            => 'PDAM',
            'EMONEY'          => 'E-Wallet',
            'TV BERLANGGANAN' => 'TV Kabel',
            default           => $group,
        };
    }

    private function extractNominal(string $name): ?int
    {
        // Extract dari nama produk: "TELKOMSEL SIMPATI / AS 10RB" → 10000
        if (preg_match('/(\d+)RB\b/i', $name, $m)) return (int) $m[1] * 1000;
        if (preg_match('/(\d+)K\b/i', $name, $m))  return (int) $m[1] * 1000;
        if (preg_match('/(\d+)JT\b/i', $name, $m)) return (int) $m[1] * 1000000;
        if (preg_match('/Rp\.?\s*(\d{1,3}(?:[\.,]\d{3})+|\d+)/i', $name, $m)) {
            return (int) str_replace(['.', ','], '', $m[1]);
        }
        return null;
    }
}
