<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('travel_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('promo_id')->nullable()->after('total_price');
            $table->decimal('promo_discount', 12, 2)->default(0)->after('promo_id');
        });
    }

    public function down(): void
    {
        Schema::table('travel_bookings', function (Blueprint $table) {
            $table->dropColumn(['promo_id', 'promo_discount']);
        });
    }
};
