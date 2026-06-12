<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('discount_arahinn', 12, 2)->nullable()->after('promo_discount');
            $table->decimal('discount_owner', 12, 2)->nullable()->after('discount_arahinn');
            $table->decimal('owner_payout', 12, 2)->nullable()->after('discount_owner');     // diterima owner (skema beban diskon)
            $table->decimal('commission_profit', 12, 2)->nullable()->after('owner_payout');   // laba komisi ArahInn (bisa minus)
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['discount_arahinn', 'discount_owner', 'owner_payout', 'commission_profit']);
        });
    }
};
