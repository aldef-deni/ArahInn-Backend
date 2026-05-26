<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            if (!Schema::hasColumn('rate_plans', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('active');
                $table->index(['hotel_id', 'is_default']);
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'rate_plan_id')) {
                $table->foreignId('rate_plan_id')
                      ->nullable()
                      ->after('room_id')
                      ->constrained('rate_plans')
                      ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'rate_plan_id')) {
                $table->dropForeign(['rate_plan_id']);
                $table->dropColumn('rate_plan_id');
            }
        });

        Schema::table('rate_plans', function (Blueprint $table) {
            if (Schema::hasColumn('rate_plans', 'is_default')) {
                $table->dropIndex(['hotel_id', 'is_default']);
                $table->dropColumn('is_default');
            }
        });
    }
};
