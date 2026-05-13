<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->foreignId('parent_rate_plan_id')
                  ->nullable()
                  ->after('hotel_id')
                  ->constrained('rate_plans')
                  ->nullOnDelete();
            $table->decimal('discount_percent', 5, 2)
                  ->nullable()
                  ->after('parent_rate_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropForeign(['parent_rate_plan_id']);
            $table->dropColumn(['parent_rate_plan_id', 'discount_percent']);
        });
    }
};
