<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redeem poin loyalitas untuk PPOB & tiket travel.
 * Menyimpan potongan poin (Rupiah, 1 poin = Rp1) agar total benar, bisa
 * ditampilkan di rincian, dan dikembalikan bila transaksi gagal/batal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppob_transactions', function (Blueprint $table) {
            $table->decimal('loyalty_discount', 12, 2)->default(0)->after('total_amount');
        });

        Schema::table('travel_bookings', function (Blueprint $table) {
            $table->decimal('loyalty_discount', 12, 2)->default(0)->after('promo_discount');
        });
    }

    public function down(): void
    {
        Schema::table('ppob_transactions', function (Blueprint $table) {
            $table->dropColumn('loyalty_discount');
        });

        Schema::table('travel_bookings', function (Blueprint $table) {
            $table->dropColumn('loyalty_discount');
        });
    }
};
