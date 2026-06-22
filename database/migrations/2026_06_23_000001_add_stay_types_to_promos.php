<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope promo ke tipe menginap: ['daily','weekly','monthly'].
 * NULL/kosong = HANYA harian (perilaku lama dipertahankan — long-stay dulu dikecualikan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->json('stay_types')->nullable()->after('product_types');
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn('stay_types');
        });
    }
};
