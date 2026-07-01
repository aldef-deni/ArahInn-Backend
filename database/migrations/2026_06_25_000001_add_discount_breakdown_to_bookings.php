<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Pisahkan diskon campaign (otomatis, tanpa kode) vs kode promo,
            // supaya label rincian ("setelah diskon promo/campaign") bisa akurat.
            $table->decimal('campaign_discount', 12, 2)->nullable()->after('promo_discount');
            $table->decimal('code_discount', 12, 2)->nullable()->after('campaign_discount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['campaign_discount', 'code_discount']);
        });
    }
};
