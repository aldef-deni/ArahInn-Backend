<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('room_prices', 'softblock_count')) {
                $table->unsignedSmallInteger('softblock_count')->default(0)->after('is_available');
            }
            if (!Schema::hasColumn('room_prices', 'min_stay')) {
                $table->unsignedSmallInteger('min_stay')->nullable()->after('softblock_count');
            }
            if (!Schema::hasColumn('room_prices', 'max_stay')) {
                $table->unsignedSmallInteger('max_stay')->nullable()->after('min_stay');
            }
            if (!Schema::hasColumn('room_prices', 'closed_to_arrival')) {
                $table->boolean('closed_to_arrival')->default(false)->after('max_stay');
            }
            if (!Schema::hasColumn('room_prices', 'closed_to_departure')) {
                $table->boolean('closed_to_departure')->default(false)->after('closed_to_arrival');
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_prices', function (Blueprint $table) {
            foreach (['softblock_count','min_stay','max_stay','closed_to_arrival','closed_to_departure'] as $col) {
                if (Schema::hasColumn('room_prices', $col)) $table->dropColumn($col);
            }
        });
    }
};
