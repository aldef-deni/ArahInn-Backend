<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    /* ──────────────────────────────────────────────────────────────────────
     * Payment Gateway (DOKU vs alternate) — legacy
     * ───────────────────────────────────────────────────────────────────── */

    public function getGateways()
    {
        $settings = Cache::get('settings:payment_gateways', ['active' => 'midtrans', 'available' => ['midtrans', 'xendit']]);
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

        Cache::forever('settings:payment_gateways', $settings);

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
        Cache::forever('settings:payment_mode', $data['mode']);

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
        $data = $request->validate([
            'bank_name'      => 'required|string|max:50',
            'account_number' => 'required|string|max:30',
            'account_name'   => 'required|string|max:100',
            'expires_hours'  => 'nullable|integer|min:1|max:168', // max 7 hari
        ]);

        $settings = [
            'bank_name'      => trim($data['bank_name']),
            'account_number' => trim($data['account_number']),
            'account_name'   => trim($data['account_name']),
            'expires_hours'  => (int) ($data['expires_hours'] ?? 24),
            'updated_by'     => $request->user()->id,
            'updated_at'     => now()->toIso8601String(),
        ];

        Cache::forever('settings:payment_manual_bank', $settings);

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

        Cache::forever('settings:maintenance_mode', $settings);

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

        Cache::forever('settings:ppn_tax', $settings);

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
        Cache::forever('settings:travel_markup', $settings);

        return response()->json([
            'success' => true,
            'data'    => $settings,
            'message' => 'Markup travel: Rp ' . number_format($settings['amount'], 0, ',', '.') . ' / penumpang.',
        ]);
    }

    /** Markup travel flat per pax. Default Rp 7.500. */
    public static function travelMarkup(): array
    {
        $override = Cache::get('settings:travel_markup');
        return ['amount' => (int) ($override['amount'] ?? 7500)];
    }

    /**
     * PPN setting. Default: enabled (preserve perilaku lama) dengan
     * persen dari config ota.tax_percent (11%).
     */
    public static function ppnTax(): array
    {
        $override     = Cache::get('settings:ppn_tax');
        $defaultPct   = (float) config('ota.tax_percent', 11);

        return [
            'enabled' => (bool) ($override['enabled'] ?? true),
            'percent' => (float) ($override['percent'] ?? $defaultPct),
        ];
    }

    public static function maintenanceMode(): array
    {
        $override = Cache::get('settings:maintenance_mode');
        return [
            'enabled' => (bool) ($override['enabled'] ?? false),
            'message' => $override['message'] ?? null,
        ];
    }

    public static function paymentMode(): string
    {
        $override = Cache::get('settings:payment_mode');
        if ($override && in_array($override, ['doku', 'manual'], true)) {
            return $override;
        }
        return config('services.payment.mode', 'doku');
    }

    public static function manualBank(): array
    {
        $override = Cache::get('settings:payment_manual_bank');
        $config   = config('services.payment.manual_bank', []);

        return [
            'bank_name'      => $override['bank_name']      ?? ($config['bank_name']      ?? 'BCA'),
            'account_number' => $override['account_number'] ?? ($config['account_number'] ?? ''),
            'account_name'   => $override['account_name']   ?? ($config['account_name']   ?? ''),
            'expires_hours'  => $override['expires_hours']  ?? ($config['expires_hours']  ?? 24),
        ];
    }
}
