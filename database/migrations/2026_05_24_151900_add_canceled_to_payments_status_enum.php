<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah 'canceled' ke enum status di tabel payments.
     *
     * Konteks: saat customer ubah metode pembayaran di halaman payment
     * (mis. dari BCA → Mandiri), VA lama harus di-cancel sebelum VA baru dibuat.
     * Sebelumnya enum cuma: pending, settlement, failed, expired, refunded.
     * Sekarang tambah: canceled.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending','settlement','failed','expired','refunded','canceled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Sebelum rollback, ubah semua row canceled jadi expired supaya tidak data-loss
        DB::table('payments')->where('status', 'canceled')->update(['status' => 'expired']);
        DB::statement("ALTER TABLE payments MODIFY COLUMN status ENUM('pending','settlement','failed','expired','refunded') NOT NULL DEFAULT 'pending'");
    }
};
