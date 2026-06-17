<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'voucher_sent_at')) {
                $table->timestamp('voucher_sent_at')->nullable()->after('issued_at');
            }
            if (!Schema::hasColumn('bookings', 'voucher_error')) {
                $table->string('voucher_error', 500)->nullable()->after('voucher_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['voucher_sent_at', 'voucher_error'] as $c) {
                if (Schema::hasColumn('bookings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
