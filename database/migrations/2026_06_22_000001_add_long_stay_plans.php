<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opsi/varian menginap lama: tiap durasi (mingguan/bulanan) bisa punya beberapa
 * opsi dengan label + deskripsi + harga sendiri (mis. "Tanpa IPL" / "Termasuk IPL").
 * Format JSON: [{ "label": "...", "desc": "...", "price": 1500000 }, ...]
 * Harga tunggal lama (weekly_price/monthly_price) tetap sebagai fallback 1 opsi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->json('weekly_plans')->nullable()->after('weekly_price');
            $table->json('monthly_plans')->nullable()->after('monthly_price');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Label opsi menginap lama yang dipilih customer (utk voucher/invoice/laporan).
            $table->string('stay_plan_label')->nullable()->after('stay_type');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['weekly_plans', 'monthly_plans']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('stay_plan_label');
        });
    }
};
