<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── PROMOS ────────────────────────────────────────
        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->nullable()->unique();
            $table->enum('type', ['voucher', 'flash_sale', 'loyalty']);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('discount_type', ['percent', 'fixed'])->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->decimal('min_purchase', 12, 2)->default(0);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->unsignedInteger('quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'start_date', 'end_date']);
        });

        // ── BOOKINGS ──────────────────────────────────────
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 20)->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('room_id');
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('total_nights');
            $table->unsignedSmallInteger('guests')->default(1);
            // Pricing breakdown
            $table->decimal('base_price', 12, 2);
            $table->decimal('markup_amount', 12, 2)->default(0);
            $table->decimal('promo_discount', 12, 2)->default(0);
            $table->decimal('loyalty_discount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2);
            $table->unsignedSmallInteger('price_suffix')->nullable(); // 3-digit unique
            // Status
            $table->enum('status', ['pending','paid','issued','canceled','refunded','rescheduled'])->default('pending');
            $table->unsignedBigInteger('promo_id')->nullable();
            $table->string('voucher_code', 50)->nullable();
            $table->text('notes')->nullable();
            // Guest info
            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_phone', 20)->nullable();
            // Timestamps
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('hotel_id')->references('id')->on('hotels');
            $table->foreign('room_id')->references('id')->on('rooms');
            $table->foreign('promo_id')->references('id')->on('promos')->nullOnDelete();
            $table->index(['user_id', 'status']);
            $table->index(['hotel_id', 'status']);
            $table->index(['room_id', 'check_in', 'check_out']);
        });

        // ── PAYMENTS ──────────────────────────────────────
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->decimal('amount', 12, 2);
            $table->string('method', 50)->nullable();
            $table->string('gateway', 50)->nullable(); // midtrans|xendit
            $table->string('gateway_trx_id')->nullable();
            $table->enum('status', ['pending','settlement','failed','expired','refunded'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->index(['booking_id', 'status']);
        });

        // ── LOYALTY POINTS ────────────────────────────────
        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->integer('points'); // bisa negatif saat redeem
            $table->enum('type', ['earn', 'redeem', 'expire']);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            $table->index(['user_id', 'expires_at']);
        });

        // ── ACTIVITY LOGS ─────────────────────────────────
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 100);
            $table->string('entity', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'action']);
            $table->index('created_at');
        });

        // ── CHAT ROOMS ────────────────────────────────────
        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('hotel_id');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('hotel_id')->references('id')->on('hotels');
        });

        // ── CHAT MESSAGES ─────────────────────────────────
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('sender_id');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->foreign('room_id')->references('id')->on('chat_rooms')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users');
            $table->index(['room_id', 'created_at']);
        });

        // ── QUEUE JOBS (tanpa Redis) ──────────────────────
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // ── CACHE TABLE (tanpa Redis) ─────────────────────
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        // ── SESSIONS ──────────────────────────────────────
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['sessions','cache_locks','cache','failed_jobs','jobs','chat_messages',
            'chat_rooms','activity_logs','loyalty_points','payments','bookings','promos'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
