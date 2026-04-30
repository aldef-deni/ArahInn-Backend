<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('address');
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('country', 100)->default('Indonesia');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->unsignedTinyInteger('star_rating')->nullable();
            $table->json('facilities')->nullable();
            $table->json('images')->nullable();
            $table->enum('status', ['pending', 'approved', 'blocked'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['city', 'status']);
            $table->index('status');
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hotel_id');
            $table->string('name');
            $table->string('type', 50)->default('standard');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('max_guests')->default(2);
            $table->decimal('base_price', 12, 2);
            $table->json('facilities')->nullable();
            $table->json('images')->nullable();
            $table->unsignedSmallInteger('total_units')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('hotel_id')->references('id')->on('hotels')->onDelete('cascade');
            $table->index(['hotel_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('hotels');
    }
};
