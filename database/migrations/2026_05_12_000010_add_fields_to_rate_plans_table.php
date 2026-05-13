<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->string('type')->default('custom')->after('description');
            $table->unsignedSmallInteger('min_nights')->default(1)->after('type');
            $table->unsignedSmallInteger('max_nights')->nullable()->after('min_nights');
            $table->string('meal_plan')->default('none')->after('max_nights');          // none | available
            $table->json('meal_options')->nullable()->after('meal_plan');               // ['sarapan','makan_siang',...]
            $table->string('cancellation_type')->default('no_refund')->after('cancelable'); // no_refund | custom
            $table->json('cancellation_detail')->nullable()->after('cancellation_type');
            $table->string('tariff_mode')->default('property')->after('cancellation_detail'); // property | static
            $table->string('booking_period')->default('anytime')->after('tariff_mode');
            $table->string('stay_period')->default('anytime')->after('booking_period');
            $table->string('advance_booking')->default('anytime')->after('stay_period');
            $table->boolean('blackout_enabled')->default(false)->after('advance_booking');
            $table->json('blackout_dates')->nullable()->after('blackout_enabled');
            $table->boolean('child_pricing_enabled')->default(false)->after('blackout_dates');
            $table->json('target_settings')->nullable()->after('child_pricing_enabled');
            $table->json('room_ids')->nullable()->after('target_settings');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn([
                'type', 'min_nights', 'max_nights', 'meal_plan', 'meal_options',
                'cancellation_type', 'cancellation_detail', 'tariff_mode',
                'booking_period', 'stay_period', 'advance_booking',
                'blackout_enabled', 'blackout_dates', 'child_pricing_enabled',
                'target_settings', 'room_ids',
            ]);
        });
    }
};
