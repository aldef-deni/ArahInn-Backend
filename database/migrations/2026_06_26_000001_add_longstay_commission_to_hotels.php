<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            // Komisi terpisah untuk long-stay. NULL = belum diatur → tanpa komisi
            // (opt-in per properti). commission_percent (lama) tetap untuk harian.
            $table->decimal('commission_percent_weekly', 5, 2)->nullable()->after('commission_percent');
            $table->decimal('commission_percent_monthly', 5, 2)->nullable()->after('commission_percent_weekly');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['commission_percent_weekly', 'commission_percent_monthly']);
        });
    }
};
