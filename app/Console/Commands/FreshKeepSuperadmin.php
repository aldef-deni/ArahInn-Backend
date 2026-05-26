<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

/**
 * Bersihkan database, sisakan hanya superadmin + data master.
 *
 * Yang DIPERTAHANKAN:
 *   - User dengan role superadmin
 *   - Tabel: migrations, roles, permissions, role_has_permissions,
 *            payment_methods, settings (kalau ada)
 *
 * Yang DIHAPUS (TRUNCATE, auto-increment reset ke 1):
 *   - Semua transaksi: bookings, payments, reviews, loyalty_points, dst
 *   - Semua properti: hotels, rooms, rate_plans, room_prices, dst
 *   - Semua user selain superadmin
 *   - Semua relasi role/permission untuk user yang dihapus
 *
 * Yang TIDAK DISENTUH:
 *   - File fisik di storage/app/public/* (foto hotel, avatar, dll)
 *
 * Usage:
 *   php artisan db:fresh-keep-superadmin
 *   php artisan db:fresh-keep-superadmin --force   (skip confirmation)
 */
class FreshKeepSuperadmin extends Command
{
    protected $signature = 'db:fresh-keep-superadmin {--force : Skip confirmation prompt}';
    protected $description = 'Bersihkan database, sisakan hanya superadmin + tabel master';

    /**
     * Tabel yang DIPRESERVE seluruhnya.
     *
     * PENTING: model_has_roles & model_has_permissions MASUK preserve.
     * Kalau di-truncate, superadmin akan kehilangan role-nya → semua endpoint
     * yang butuh middleware role:superadmin akan return 403. Orphan entries
     * untuk user yang dihapus dibersihkan terpisah di logic handle().
     */
    private array $preserveTables = [
        'migrations',
        'roles',
        'permissions',
        'role_has_permissions',
        'model_has_roles',
        'model_has_permissions',
        'payment_methods',
        'settings',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'failed_jobs',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    public function handle(): int
    {
        $superadmins = User::role('superadmin')->get(['id', 'name', 'email']);

        if ($superadmins->isEmpty()) {
            $this->error('Tidak ada user dengan role superadmin. Aborting untuk safety.');
            return self::FAILURE;
        }

        $this->info('Superadmin yang akan DIPERTAHANKAN:');
        $this->table(['ID', 'Name', 'Email'], $superadmins->map(fn($u) => [$u->id, $u->name, $u->email])->toArray());

        $superadminIds = $superadmins->pluck('id')->toArray();

        // List tabel di DB
        $allTables = collect(DB::select('SHOW TABLES'))
            ->map(fn($t) => array_values((array) $t)[0])
            ->all();

        $toTruncate = array_values(array_diff($allTables, $this->preserveTables, ['users']));

        $this->newLine();
        $this->warn('Tabel yang akan di-TRUNCATE (data hilang, struktur tetap, AI reset ke 1):');
        $this->line('  ' . implode(', ', $toTruncate));

        $this->newLine();
        $this->info('Tabel yang DIPRESERVE penuh:');
        $this->line('  ' . implode(', ', array_intersect($this->preserveTables, $allTables)));

        $this->newLine();
        $this->warn("Tabel `users`: akan dihapus SEMUA kecuali ID [" . implode(', ', $superadminIds) . "]");
        $this->warn("Tabel `model_has_roles` & `model_has_permissions`: akan dibersihkan dari assignment orphan");

        $this->newLine();
        if (!$this->option('force') && !$this->confirm('Lanjutkan? Aksi ini TIDAK BISA DI-UNDO. Pastikan sudah backup DB.', false)) {
            $this->info('Dibatalkan.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Memulai pembersihan...');

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');

            // 1. Truncate semua tabel transaksi
            foreach ($toTruncate as $table) {
                DB::table($table)->truncate();
                $this->line("  ✓ truncated: {$table}");
            }

            // 2. Hapus user non-superadmin
            $deletedUsers = DB::table('users')->whereNotIn('id', $superadminIds)->delete();
            $this->line("  ✓ deleted {$deletedUsers} non-superadmin users");

            // 3. Bersihkan model_has_roles untuk model_type=User yang tidak existing
            if (Schema::hasTable('model_has_roles')) {
                $userClass = User::class;
                $orphanRoles = DB::table('model_has_roles')
                    ->where('model_type', $userClass)
                    ->whereNotIn('model_id', $superadminIds)
                    ->delete();
                $this->line("  ✓ cleaned {$orphanRoles} orphan role assignments");
            }

            if (Schema::hasTable('model_has_permissions')) {
                $userClass = User::class;
                $orphanPerms = DB::table('model_has_permissions')
                    ->where('model_type', $userClass)
                    ->whereNotIn('model_id', $superadminIds)
                    ->delete();
                $this->line("  ✓ cleaned {$orphanPerms} orphan permission assignments");
            }

            // 4. Reset AUTO_INCREMENT tabel users supaya superadmin tetap ID-nya & user baru lanjut
            //    (tidak di-reset paksa karena bisa konflik dengan superadmin ID existing)
            $maxId = DB::table('users')->max('id') ?? 0;
            DB::statement("ALTER TABLE users AUTO_INCREMENT = " . ($maxId + 1));
            $this->line("  ✓ users AUTO_INCREMENT set to " . ($maxId + 1));

            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            $this->newLine();
            $this->info('✓ Database berhasil dibersihkan.');
            $this->info("Sisa: " . DB::table('users')->count() . " user (superadmin).");

            // 5. Clear cache supaya tidak ada residue
            $this->call('cache:clear');
            $this->call('config:clear');

            return self::SUCCESS;

        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            $this->error('Gagal: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());
            return self::FAILURE;
        }
    }
}
