<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    /* ──────────────────────────────────────────────────────────────────────
     * Penyimpanan setting: DB (permanen) + cache (lapisan baca cepat).
     *
     * writeSetting() menulis ke tabel `settings` SEKALIGUS update cache.
     * readSetting()  baca dari cache; saat cache kosong (mis. habis di-clear
     * atau setelah deploy) otomatis rebuild dari DB → nilai TIDAK hilang.
     *
     * try/catch menjaga agar tetap berfungsi (cache-only) seandainya migrasi
     * tabel `settings` belum dijalankan.
     * ───────────────────────────────────────────────────────────────────── */

    protected static function readSetting(string $key)
    {
        return Cache::rememberForever("settings:$key", function () use ($key) {
            try {
                return Setting::where('key', $key)->value('value');
            } catch (\Throwable $e) {
                return null; // tabel belum ada → pemanggil pakai default
            }
        });
    }

    protected static function writeSetting(string $key, $value): void
    {
        try {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        } catch (\Throwable $e) {
            // tabel belum ada — minimal cache supaya tetap jalan sesi ini
        }
        Cache::forever("settings:$key", $value);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Payment Gateway (DOKU vs alternate) — legacy
     * ───────────────────────────────────────────────────────────────────── */

    public function getGateways()
    {
        $settings = self::readSetting('payment_gateways') ?? ['active' => 'midtrans', 'available' => ['midtrans', 'xendit']];
        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function setGateway(Request $request)
    {
        $data = $request->validate(['active' => 'required|in:midtrans,xendit']);
        $settings = [
            'active'     => $data['active'],
            'available'  => ['midtrans', 'xendit'],
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ];

        self::writeSetting('payment_gateways', $settings);

        return response()->json(['success' => true, 'data' => $settings]);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Payment Mode (doku/manual) — admin-controlled at runtime
     * ───────────────────────────────────────────────────────────────────── */

    public function getPaymentMode()
    {
        return response()->json([
            'success' => true,
            'data'    => ['mode' => self::paymentMode()],
        ]);
    }

    public function setPaymentMode(Request $request)
    {
        $data = $request->validate(['mode' => 'required|in:doku,manual']);
        self::writeSetting('payment_mode', $data['mode']);

        return response()->json([
            'success' => true,
            'data'    => ['mode' => $data['mode']],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Manual Bank Transfer settings (rekening tujuan + nama + expires)
     * ───────────────────────────────────────────────────────────────────── */

    public function getPaymentManual()
    {
        return response()->json([
            'success' => true,
            'data'    => self::manualBank(),
        ]);
    }

    public function setPaymentManual(Request $request)
    {
        // Field rekening OPSIONAL → boleh ubah sebagian (mis. hanya batas jam).
        // Yang tidak dikirim/kosong tetap pakai nilai existing (tidak perlu isi ulang).
        $data = $request->validate([
            'bank_name'      => 'nullable|string|max:50',
            'account_number' => 'nullable|string|max:30',
            'account_name'   => 'nullable|string|max:100',
            'expires_hours'  => 'nullable|integer|min:1|max:168', // max 7 hari
        ]);

        $existing = self::manualBank();
        $merge = function (string $k) use ($data, $existing) {
            return (isset($data[$k]) && trim((string) $data[$k]) !== '')
                ? trim((string) $data[$k]) : $existing[$k];
        };

        $accountNumber = $merge('account_number');
        $accountName   = $merge('account_name');

        // Tetap wajib terisi MINIMAL sekali (existing atau input baru).
        if ($accountNumber === '' || $accountName === '') {
            return response()->json([
                'success' => false,
                'message' => 'Nomor rekening & atas nama wajib diisi (minimal sekali).',
            ], 422);
        }

        $settings = [
            'bank_name'      => $merge('bank_name') ?: 'BCA',
            'account_number' => $accountNumber,
            'account_name'   => $accountName,
            'expires_hours'  => (int) ((isset($data['expires_hours']) && $data['expires_hours'])
                ? $data['expires_hours'] : ($existing['expires_hours'] ?? 24)),
            'updated_by'     => $request->user()->id,
            'updated_at'     => now()->toIso8601String(),
        ];

        self::writeSetting('payment_manual_bank', $settings);

        return response()->json([
            'success' => true,
            'data'    => $settings,
            'message' => 'Rekening pembayaran manual berhasil diperbarui.',
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Maintenance Mode — toggle untuk customer-facing site
     * Saat aktif: arahinn.com customer routes redirect ke halaman Maintenance.
     * Admin/owner portal tetap accessible.
     * ───────────────────────────────────────────────────────────────────── */

    public function getMaintenanceMode()
    {
        return response()->json([
            'success' => true,
            'data'    => self::maintenanceMode(),
        ]);
    }

    public function setMaintenanceMode(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'message' => 'nullable|string|max:300',
        ]);

        $settings = [
            'enabled'    => (bool) $data['enabled'],
            'message'    => $data['message'] ?? null,
            'updated_by' => $request->user()->id,
            'updated_at' => now()->toIso8601String(),
        ];

        self::writeSetting('maintenance_mode', $settings);

        return response()->json([
            'success' => true,
            'data'    => $settings,
            'message' => $data['enabled'] ? 'Maintenance mode AKTIF.' : 'Maintenance mode dimatikan.',
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * PPN (Pajak Pertambahan Nilai) — toggle on/off + atur persen.
     * Saat enabled=false: PPN tidak ditambahkan ke total booking.
     * Catatan: ini HANYA PPN. Markup "Pajak & Others" (komisi + PPh 2%)
     * adalah komponen terpisah dan tidak terpengaruh toggle ini.
     * ───────────────────────────────────────────────────────────────────── */

    public function getPpnTax()
    {
        return response()->json([
            'success' => true,
            'data'    => self::ppnTax(),
        ]);
    }

    public function setPpnTax(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $settings = [
            'enabled'    => (bool) $data['enabled'],
            'percent'    => isset($data['percent'])
                ? round((float) $data['percent'], 2)
                : self::ppnTax()['percent'],
            'updated_by' => $request->user()->id,
            'updated_at' => now()->toIso8601String(),
        ];

        self::writeSetting('ppn_tax', $settings);

        return response()->json([
            'success' => true,
            'data'    => $settings,
            'message' => $data['enabled']
                ? "PPN {$settings['percent']}% AKTIF untuk booking baru."
                : 'PPN dimatikan. Booking baru tidak dikenakan PPN.',
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Static helpers — dipanggil dari PaymentService/Controller
     *
     * Override .env config kalau ada cache; fallback ke config().
     * Pattern ini supaya legacy .env tetap jalan kalau cache di-clear.
     * ───────────────────────────────────────────────────────────────────── */

    /* ──────────────────────────────────────────────────────────────────────
     * Markup Travel — biaya layanan flat per penumpang (di atas harga vendor).
     * Berlaku untuk semua moda: kereta, pesawat, bus, pelni.
     * ───────────────────────────────────────────────────────────────────── */

    public function getTravelMarkup()
    {
        return response()->json(['success' => true, 'data' => self::travelMarkup()]);
    }

    public function setTravelMarkup(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:0|max:1000000',
        ]);

        $settings = [
            'amount'     => (int) $data['amount'],
            'updated_by' => $request->user()->id,
            'updated_at' => now()->toIso8601String(),
        ];
        self::writeSetting('travel_markup', $settings);

        return response()->json([
            'success' => true,
            'data'    => $settings,
            'message' => 'Markup travel: Rp ' . number_format($settings['amount'], 0, ',', '.') . ' / penumpang.',
        ]);
    }

    /** Markup travel flat per pax. Default Rp 7.500. */
    public static function travelMarkup(): array
    {
        $override = self::readSetting('travel_markup');
        return ['amount' => (int) ($override['amount'] ?? 7500)];
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Nomor WhatsApp konsultasi Design Interior — bisa diubah admin.
     * Dipakai tombol "Mulai Konsultasi" di halaman /interior.
     * ───────────────────────────────────────────────────────────────────── */

    public function getInteriorWa()
    {
        return response()->json(['success' => true, 'data' => self::interiorWa()]);
    }

    public function setInteriorWa(Request $request)
    {
        $data = $request->validate([
            'number'  => 'required|string|max:20',
            'message' => 'nullable|string|max:300',
        ]);

        // Normalisasi ke format internasional tanpa simbol (mis. 62812xxxx)
        $num = preg_replace('/\D+/', '', $data['number']);
        if (str_starts_with($num, '0'))      $num = '62' . substr($num, 1);
        elseif (str_starts_with($num, '8'))  $num = '62' . $num;

        $settings = [
            'number'     => $num,
            'message'    => $data['message'] ?: self::interiorWa()['message'],
            'updated_by' => $request->user()->id,
            'updated_at' => now()->toIso8601String(),
        ];
        self::writeSetting('interior_wa', $settings);

        return response()->json([
            'success' => true,
            'data'    => $settings,
            'message' => 'Nomor WhatsApp konsultasi diperbarui.',
        ]);
    }

    /** Nomor WA konsultasi interior. Default 6282181111618. */
    public static function interiorWa(): array
    {
        $override = self::readSetting('interior_wa');
        return [
            'number'  => $override['number']  ?? '6282181111618',
            'message' => $override['message'] ?? 'Halo ArahInn, saya ingin konsultasi Design Interior.',
        ];
    }

    /**
     * PPN setting. Default: DISABLED (mati) — saat cache kosong / belum pernah
     * di-set, PPN tidak aktif. Superadmin harus menyalakan manual.
     * Persen default dari config ota.tax_percent (11%) hanya dipakai sbg nilai
     * awal kolom % saat PPN dinyalakan, bukan menentukan enabled.
     */
    public static function ppnTax(): array
    {
        $override     = self::readSetting('ppn_tax');
        $defaultPct   = (float) config('ota.tax_percent', 11);

        return [
            'enabled' => (bool) ($override['enabled'] ?? false),
            'percent' => (float) ($override['percent'] ?? $defaultPct),
        ];
    }

    public static function maintenanceMode(): array
    {
        $override = self::readSetting('maintenance_mode');
        return [
            'enabled' => (bool) ($override['enabled'] ?? false),
            'message' => $override['message'] ?? null,
        ];
    }

    public static function paymentMode(): string
    {
        $override = self::readSetting('payment_mode');
        if ($override && in_array($override, ['doku', 'manual'], true)) {
            return $override;
        }
        return config('services.payment.mode', 'doku');
    }

    public static function manualBank(): array
    {
        $override = self::readSetting('payment_manual_bank');
        $override = is_array($override) ? $override : [];
        $config   = config('services.payment.manual_bank', []);

        // Ambil dari override; bila kosong/null/tidak ada → fallback ke config (default).
        // (operator ?? sebelumnya TIDAK fallback saat nilai berupa string kosong "")
        $pick = function (string $k, $default) use ($override, $config) {
            $v = $override[$k] ?? null;
            if ($v !== null && $v !== '') return $v;
            return $config[$k] ?? $default;
        };

        return [
            'bank_name'      => $pick('bank_name', 'BCA'),
            'account_number' => $pick('account_number', ''),
            'account_name'   => $pick('account_name', ''),
            'expires_hours'  => $pick('expires_hours', 24),
        ];
    }
}
