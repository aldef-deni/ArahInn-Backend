<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── PPOB Categories ────────────────────────────────────────────
        // pulsa, paket_data, pln_token, pln_postpaid, pdam, bpjs,
        // telkom, indihome, ewallet_ovo, ewallet_gopay, dll.
        Schema::create('ppob_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();                    // 'pulsa', 'pln_token', dst
            $table->string('name');                                  // "Pulsa Prabayar"
            $table->string('group', 30);                             // 'pulsa', 'pln', 'tagihan', 'ewallet'
            $table->enum('type', ['prabayar', 'pascabayar']);
            $table->string('icon', 50)->nullable();                  // nama icon (lucide / asset)
            $table->string('color', 20)->nullable();                 // hex untuk badge
            $table->decimal('markup_amount', 12, 2)->default(0);     // flat markup (Rp) per produk
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['group', 'is_active']);
            $table->index('sort_order');
        });

        // ── PPOB Products (synced dari Raja Biller) ───────────────────
        Schema::create('ppob_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('ppob_categories')->cascadeOnDelete();
            $table->string('raja_biller_code', 100)->unique();       // kode produk vendor (mis. "T5", "XL10")
            $table->string('name');                                  // "Telkomsel 5.000"
            $table->string('operator', 50)->nullable();              // "Telkomsel", "PLN", dst (untuk grouping di UI)
            $table->bigInteger('nominal')->default(0);               // nominal (Rp 5.000, 10.000, dst)
            $table->decimal('price_buy', 12, 2);                     // harga beli dari Raja Biller
            $table->decimal('price_sell', 12, 2);                    // harga jual ke customer (buy + markup)
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive', 'gangguan'])->default('active');
            $table->json('meta')->nullable();                        // payload raw dari Raja Biller (untuk debug)
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->index('operator');
            $table->index('nominal');
        });

        // ── PPOB Transactions ─────────────────────────────────────────
        Schema::create('ppob_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('trx_code', 32)->unique();                // internal kode: PPOB-XXXXXXX
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('ppob_products')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('ppob_categories')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            // Snapshot product saat transaksi (price bisa berubah, tapi trx historical harus stabil)
            $table->string('product_name');
            $table->string('product_code', 100);
            $table->string('customer_number', 50);                   // nomor HP/PLN/PDAM/dll
            $table->string('customer_name')->nullable();             // dari inquiry (pascabayar)
            $table->decimal('price_buy', 12, 2);
            $table->decimal('price_sell', 12, 2);
            $table->decimal('admin_fee', 12, 2)->default(0);         // biaya admin (PLN postpaid, dll)
            $table->decimal('total_amount', 12, 2);                  // yang dibayar customer

            // Status flow:
            //   pending  → menunggu pembayaran (VA dibuat tapi belum di-bayar)
            //   paid     → customer sudah bayar, antrian eksekusi Raja Biller
            //   processing → request ke Raja Biller sudah dikirim, tunggu response
            //   success  → Raja Biller sukses, token/PIN sudah dikirim
            //   failed   → Raja Biller gagal eksekusi
            //   refundable → failed dan customer berhak refund
            //   refunded → admin sudah refund manual
            //   canceled → dibatalkan sebelum dibayar
            $table->enum('status', [
                'pending', 'paid', 'processing', 'success', 'failed',
                'refundable', 'refunded', 'canceled'
            ])->default('pending');

            // Detail eksekusi Raja Biller
            $table->string('raja_biller_ref', 100)->nullable();      // reference dari Raja Biller
            $table->string('serial_number', 200)->nullable();        // token PLN / SN voucher
            $table->json('raja_biller_payload')->nullable();         // full response dari Raja Biller
            $table->text('failure_reason')->nullable();              // kalau failed

            // Refund tracking
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_notes')->nullable();

            // Timestamps
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('executed_at')->nullable();            // saat Raja Biller di-call
            $table->timestamp('completed_at')->nullable();           // success / failed final

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('customer_number');
        });

        // ── Extend payments table: payment_type discrimination ──────────
        // Existing payments untuk booking hotel, sekarang shared dengan ppob.
        Schema::table('payments', function (Blueprint $table) {
            // 'booking' (default) untuk akomodasi, 'ppob' untuk transaksi PPOB
            $table->string('payment_type', 20)->default('booking')->after('id')->index();
            // Polymorphic-ish ref: bisa booking_id ATAU ppob_transaction_id
            $table->unsignedBigInteger('ppob_transaction_id')->nullable()->after('booking_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'ppob_transaction_id']);
        });
        Schema::dropIfExists('ppob_transactions');
        Schema::dropIfExists('ppob_products');
        Schema::dropIfExists('ppob_categories');
    }
};
