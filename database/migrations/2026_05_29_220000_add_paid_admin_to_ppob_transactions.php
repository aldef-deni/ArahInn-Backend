<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppob_transactions', function (Blueprint $table) {
            $table->foreignId('paid_by')->nullable()->after('paid_at')
                ->constrained('users')->nullOnDelete();
            $table->text('paid_notes')->nullable()->after('paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('ppob_transactions', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropColumn(['paid_by', 'paid_notes']);
        });
    }
};
