<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('travel_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('travel_bookings', 'group_code')) {
                // Penghubung pulang-pergi: 2 leg (depart+return) berbagi group_code yang sama.
                $table->string('group_code', 40)->nullable()->after('code')->index();
            }
            if (!Schema::hasColumn('travel_bookings', 'leg')) {
                $table->string('leg', 10)->nullable()->after('group_code'); // depart | return | null(one-way)
            }
        });
    }

    public function down(): void
    {
        Schema::table('travel_bookings', function (Blueprint $table) {
            foreach (['group_code', 'leg'] as $c) {
                if (Schema::hasColumn('travel_bookings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
