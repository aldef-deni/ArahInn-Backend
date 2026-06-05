<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * travel_bookings — pesanan tiket travel (kereta/pesawat/pelni/bus).
 * Moda-agnostic supaya bisa dipakai semua moda.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('travel_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('moda', 20);                 // kereta | pesawat | pelni | bus
            $table->string('product_code', 20)->nullable();
            $table->string('code', 30)->unique();       // kode pesanan internal ArahInn (TRV...)
            $table->string('vendor_booking_code')->nullable();
            $table->string('vendor_transaction_id')->nullable();
            $table->string('airline', 10)->nullable();

            // Trip
            $table->string('origin', 10);
            $table->string('destination', 10);
            $table->string('origin_name')->nullable();
            $table->string('destination_name')->nullable();
            $table->date('depart_date');
            $table->string('depart_time', 10)->nullable();
            $table->string('arrive_time', 10)->nullable();
            $table->string('service_name')->nullable(); // nama kereta / flight code
            $table->string('class', 20)->nullable();

            // Penumpang + harga
            $table->json('passengers')->nullable();
            $table->unsignedInteger('pax')->default(1);
            $table->unsignedBigInteger('vendor_price')->default(0); // harga vendor (potong deposit)
            $table->unsignedInteger('markup')->default(0);          // markup per pax
            $table->unsignedBigInteger('total_price')->default(0);  // yang dibayar customer

            // Status & pembayaran
            $table->string('status', 24)->default('pending_payment');
            // pending_payment | paid | issued | failed | expired | canceled
            $table->string('payment_method', 20)->nullable();
            $table->timestamp('time_limit')->nullable();  // batas bayar dari vendor
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('issued_at')->nullable();

            // E-tiket
            $table->string('url_etiket')->nullable();
            $table->string('url_struk')->nullable();
            $table->string('url_image')->nullable();

            $table->json('meta')->nullable(); // seat string, raw response, dll
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('moda');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_bookings');
    }
};
