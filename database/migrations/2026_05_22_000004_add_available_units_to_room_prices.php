<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('room_prices', 'available_units')) {
                $table->unsignedSmallInteger('available_units')->nullable()->after('is_available');
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            if (Schema::hasColumn('room_prices', 'available_units')) {
                $table->dropColumn('available_units');
            }
        });
    }
};
