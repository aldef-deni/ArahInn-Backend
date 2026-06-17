<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Override tier loyalitas manual oleh superadmin.
     * NULL = tier dihitung otomatis dari lifetime earned points.
     * Diisi (silver|gold|platinum) = dipaksa ke tier itu (mis. penurunan tier).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('loyalty_tier_override', 20)->nullable()->after('primary_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('loyalty_tier_override');
        });
    }
};
