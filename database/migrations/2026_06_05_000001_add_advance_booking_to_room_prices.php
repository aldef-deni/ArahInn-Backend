<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('room_prices', 'min_advance_days')) {
                $table->unsignedSmallInteger('min_advance_days')->nullable()->after('max_stay');
            }
            if (!Schema::hasColumn('room_prices', 'max_advance_days')) {
                $table->unsignedSmallInteger('max_advance_days')->nullable()->after('min_advance_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            foreach (['min_advance_days', 'max_advance_days'] as $col) {
                if (Schema::hasColumn('room_prices', $col)) $table->dropColumn($col);
            }
        });
    }
};
