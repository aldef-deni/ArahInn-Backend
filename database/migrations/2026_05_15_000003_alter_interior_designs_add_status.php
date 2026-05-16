<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interior_designs', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('videos');
            $table->unsignedBigInteger('owner_id')->nullable()->after('status');
        });

        // Migrate existing data: is_active=true → approved, false → pending
        DB::table('interior_designs')->where('is_active', true)->update(['status' => 'approved']);
        DB::table('interior_designs')->where('is_active', false)->update(['status' => 'pending']);

        Schema::table('interior_designs', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('interior_designs', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('videos');
            $table->dropColumn(['status', 'owner_id']);
        });
    }
};
