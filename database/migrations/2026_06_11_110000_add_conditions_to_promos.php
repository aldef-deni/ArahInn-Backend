<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            // Kondisi opsional — kalau diisi, promo hanya berlaku saat kondisi terpenuhi.
            $table->string('day_type', 10)->nullable()->after('end_date'); // weekday | weekend | null
            $table->json('hotel_types')->nullable()->after('day_type');     // ['Hotel','Villa',...] | null
            $table->string('location')->nullable()->after('hotel_types');   // mis. "Bandung" | null
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn(['day_type', 'hotel_types', 'location']);
        });
    }
};
