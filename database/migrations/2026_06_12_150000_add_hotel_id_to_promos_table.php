<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            // null = berlaku untuk SEMUA properti owner; terisi = hanya properti itu
            $table->unsignedBigInteger('hotel_id')->nullable()->after('owner_id');
            $table->index('hotel_id');
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropIndex(['hotel_id']);
            $table->dropColumn('hotel_id');
        });
    }
};
