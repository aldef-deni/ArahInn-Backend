<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interior_designs', function (Blueprint $table) {
            $table->string('wa_number')->nullable()->after('owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('interior_designs', function (Blueprint $table) {
            $table->dropColumn('wa_number');
        });
    }
};
