<?php

namespace App\Console\Commands;

use App\Services\DokuService;
use Illuminate\Console\Command;

/**
 * Diagnostic command untuk test create VA per bank tanpa booking real.
 * Sangat berguna untuk debug "Bank X tidak bisa".
 *
 * Usage:
 *   php artisan doku:test-va                    → test semua 4 bank di semua merchant
 *   php artisan doku:test-va bca                → test BCA saja di semua merchant
 *   php artisan doku:test-va bri merchant1      → test BRI di merchant1
 */
class TestDokuVA extends Command
{
    protected $signature = 'doku:test-va {bank? : bca|mandiri|bri|bsi} {merchant? : merchant key}';
    protected $description = 'Test create DOKU Virtual Account per bank tanpa booking real';

    public function handle(): int
    {
        $banks = $this->argument('bank')
            ? [strtolower($this->argument('bank'))]
            : ['bca', 'mandiri', 'bri', 'bsi'];

        $merchantArg = $this->argument('merchant');
        $merchants   = $merchantArg
            ? [$merchantArg]
            : DokuService::availableMerchantKeys();

        if (empty($merchants)) {
            $this->error('Tidak ada merchant DOKU yang aktif di config. Cek .env DOKU_M1_*, DOKU_M2_*, dll.');
            return self::FAILURE;
        }

        $svc = app(DokuService::class);
        $results = [];

        foreach ($merchants as $merchant) {
            foreach ($banks as $bank) {
                $row = ['merchant' => $merchant, 'bank' => strtoupper($bank)];
                try {
                    $svc->useMerchant($merchant);
                    $result = $svc->createVirtualAccount(
                        bank: $bank,
                        bookingCode: 'TEST-' . strtoupper($bank) . '-' . substr(uniqid(), -6),
                        bookingId: random_int(9000000, 9999999),
                        amount: 10000,
                        customer: [
                            'name'  => 'Test User',
                            'email' => 'test@arahinn.com',
                            'phone' => '08111111111',
                        ],
                        expiresAt: now()->addHour(),
                    );
                    $row['status']     = '✓ OK';
                    $row['va_number']  = $result['_va_number'] ?? '-';
                    $row['error']      = '';
                } catch (\Throwable $e) {
                    $row['status']     = '✗ FAIL';
                    $row['va_number']  = '-';
                    $row['error']      = mb_substr($e->getMessage(), 0, 120);
                }
                $results[] = $row;
            }
        }

        $this->table(['Merchant', 'Bank', 'Status', 'VA Number', 'Error'], $results);

        $failed = collect($results)->where('status', '✗ FAIL')->count();
        $this->newLine();
        if ($failed > 0) {
            $this->warn("Total {$failed} bank/merchant kombinasi gagal. Cek kolom Error untuk detail.");
            $this->line('Tips:');
            $this->line('  - "BIN ... tidak valid" → set DOKU_*_PARTNER_SERVICE_ID_<BANK> di .env');
            $this->line('  - "channel belum di-enable" → aktivasi channel di portal DOKU per merchant');
            $this->line('  - "credential ... tidak punya akses" → hubungi DOKU support');
        } else {
            $this->info('✓ Semua bank di semua merchant berhasil create VA.');
        }

        return self::SUCCESS;
    }
}
