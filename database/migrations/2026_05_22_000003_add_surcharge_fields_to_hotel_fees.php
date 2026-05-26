<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_fees', function (Blueprint $table) {
            if (!Schema::hasColumn('hotel_fees', 'category')) {
                $table->string('category', 100)->nullable()->after('name');
            }
            if (!Schema::hasColumn('hotel_fees', 'start_date')) {
                $table->date('start_date')->nullable()->after('category');
            }
            if (!Schema::hasColumn('hotel_fees', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hotel_fees', function (Blueprint $table) {
            foreach (['category','start_date','end_date'] as $col) {
                if (Schema::hasColumn('hotel_fees', $col)) $table->dropColumn($col);
            }
        });
    }
};
