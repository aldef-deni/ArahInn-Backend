<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            if (!Schema::hasColumn('hotels', 'commission_percent')) {
                // Persentase komisi dasar (selalu ditambah 2% di runtime untuk
                // mendapat markup final). Default 10 → markup final 12%.
                $table->decimal('commission_percent', 5, 2)->nullable()->after('currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            if (Schema::hasColumn('hotels', 'commission_percent')) {
                $table->dropColumn('commission_percent');
            }
        });
    }
};
