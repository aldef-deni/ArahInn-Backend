<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend PPOB tables dengan field-field spesifik dari response Rajabiller real.
 *
 * Reasoning per field — sudah dikonfirmasi via test ke devel endpoint:
 *   ppob_products:
 *     - admin_fee, komisi  → dari method 'info' (POSTPAID admin+komisi, PREPAID admin=0 komisi=0)
 *     - status_label       → "AKTIF" / "AKTIF (*Need Request)" / "GANGGUAN" / "CLOSE" raw text dari Rajabiller
 *     - last_synced_at     → timestamp sync terakhir via ppob:sync-catalog
 *     - last_callback_at   → timestamp update terakhir via callback INFO PRODUK
 *
 *   ppob_transactions:
 *     - ref1                  → unique key kita kirim ke Rajabiller (echo back sebagai trxid)
 *     - rc                    → response code raw (00/68/16/dst)
 *     - inquiry_response      → JSON full response method 'cek' (untuk POSTPAID)
 *     - payment_response      → JSON full response method 'bayar'
 *     - callback_data         → JSON payload async callback
 *     - template_struk        → JSON {no_meter, customer_name, kwh, stroom/token, dll}
 *     - struk_url             → URL public struk PDF
 *     - saldo_akhir_rajabiller → saldo mitra setelah trx (untuk monitoring saldo deposit)
 *     - inquired_at, callback_received_at, expires_at
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppob_products', function (Blueprint $table) {
            $table->decimal('admin_fee', 12, 2)->default(0)->after('price_buy');
            $table->decimal('komisi', 12, 2)->default(0)->after('admin_fee');
            $table->string('status_label', 50)->nullable()->after('status');
            $table->timestamp('last_synced_at')->nullable()->after('synced_at');
            $table->timestamp('last_callback_at')->nullable()->after('last_synced_at');
        });

        Schema::table('ppob_transactions', function (Blueprint $table) {
            // Rajabiller identifiers
            $table->string('ref1', 100)->nullable()->unique()->after('trx_code');
            $table->string('rc', 5)->nullable()->after('status');

            // Periode / extra (BPJS, PBB, Samsat, Game)
            $table->json('extra_request')->nullable()->after('customer_number');

            // Inquiry data (POSTPAID step 1)
            $table->json('inquiry_response')->nullable()->after('raja_biller_payload');
            $table->json('payment_response')->nullable()->after('inquiry_response');
            $table->json('callback_data')->nullable()->after('payment_response');

            // Receipt data
            $table->json('template_struk')->nullable()->after('callback_data');
            $table->string('struk_url', 500)->nullable()->after('template_struk');

            // Monitoring
            $table->decimal('saldo_akhir_rajabiller', 14, 2)->nullable()->after('struk_url');

            // Timestamps spesifik
            $table->timestamp('inquired_at')->nullable()->after('paid_at');
            $table->timestamp('callback_received_at')->nullable()->after('executed_at');
            $table->timestamp('expires_at')->nullable()->after('callback_received_at');

            $table->index(['rc', 'status']);
            $table->index('ref1');
        });
    }

    public function down(): void
    {
        Schema::table('ppob_products', function (Blueprint $table) {
            $table->dropColumn(['admin_fee', 'komisi', 'status_label', 'last_synced_at', 'last_callback_at']);
        });

        Schema::table('ppob_transactions', function (Blueprint $table) {
            $table->dropIndex(['rc', 'status']);
            $table->dropIndex(['ref1']);
            $table->dropColumn([
                'ref1', 'rc', 'extra_request',
                'inquiry_response', 'payment_response', 'callback_data',
                'template_struk', 'struk_url',
                'saldo_akhir_rajabiller',
                'inquired_at', 'callback_received_at', 'expires_at',
            ]);
        });
    }
};
