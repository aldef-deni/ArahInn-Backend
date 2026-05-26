<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Drop FK on hotel_id agar bisa nullable
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['hotel_id']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedBigInteger('hotel_id')->nullable()->change();
            $table->unsignedBigInteger('property_id')->nullable()->after('hotel_id');
        });

        // Re-add FK hotel_id sebagai nullable + new FK property_id
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreign('hotel_id')->references('id')->on('hotels')->cascadeOnDelete();
            $table->foreign('property_id')->references('id')->on('property_listings')->cascadeOnDelete();
            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropIndex(['property_id', 'status']);
            $table->dropColumn('property_id');
        });
    }
};
