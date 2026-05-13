<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('property_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category'); // Hotel, Apartment, Kosan, Guest House, Villa, Resort
            $table->string('listing_type')->default('sell'); // sell / rent
            $table->unsignedBigInteger('price');
            $table->boolean('price_negotiable')->default(false);
            $table->string('address')->nullable();
            $table->string('city');
            $table->string('province')->nullable();
            $table->unsignedInteger('land_area')->nullable(); // m²
            $table->unsignedInteger('building_area')->nullable(); // m²
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->string('certificate')->nullable(); // SHM, HGB, etc.
            $table->json('facilities')->nullable();
            $table->json('images')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('property_listings');
    }
};
