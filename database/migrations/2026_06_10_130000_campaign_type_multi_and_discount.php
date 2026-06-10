<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // type bisa lebih dari satu (mis. "banner,popup") → ubah enum jadi varchar.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE campaigns MODIFY type VARCHAR(50) NOT NULL DEFAULT 'banner'");
        }

        Schema::table('campaigns', function (Blueprint $table) {
            // Persentase diskon campaign (menggantikan fungsi budget pada form).
            $table->decimal('discount_percent', 5, 2)->default(0)->after('budget');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE campaigns MODIFY type ENUM('banner','email','push','popup') NOT NULL DEFAULT 'banner'");
        }
    }
};
