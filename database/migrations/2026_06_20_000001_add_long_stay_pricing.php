<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur menginap jangka panjang: harga MINGGUAN (7 malam) & BULANAN (30 malam)
 * sebagai harga TETAP per kamar (bukan kalkulasi dari harga harian).
 * Harga ini di-set owner/superadmin & TIDAK terkena promo/campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('weekly_price', 12, 2)->nullable()->after('base_price');
            $table->decimal('monthly_price', 12, 2)->nullable()->after('weekly_price');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // daily | weekly | monthly — menentukan harga & durasi (allotment per tanggal mengikuti).
            $table->string('stay_type', 12)->default('daily')->after('total_nights');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['weekly_price', 'monthly_price']);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('stay_type');
        });
    }
};
