<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            if (!Schema::hasColumn('hotels', 'booking_min_age')) {
                $table->unsignedTinyInteger('booking_min_age')->nullable()->after('star_rating');
            }
            if (!Schema::hasColumn('hotels', 'check_in_24h')) {
                $table->boolean('check_in_24h')->default(false)->after('booking_min_age');
            }
            if (!Schema::hasColumn('hotels', 'check_in_start')) {
                $table->time('check_in_start')->nullable()->after('check_in_24h');
            }
            if (!Schema::hasColumn('hotels', 'check_in_end')) {
                $table->time('check_in_end')->nullable()->after('check_in_start');
            }
            if (!Schema::hasColumn('hotels', 'check_out_start')) {
                $table->time('check_out_start')->nullable()->after('check_in_end');
            }
            if (!Schema::hasColumn('hotels', 'check_out_end')) {
                $table->time('check_out_end')->nullable()->after('check_out_start');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn([
                'booking_min_age', 'check_in_24h',
                'check_in_start', 'check_in_end',
                'check_out_start', 'check_out_end',
            ]);
        });
    }
};
