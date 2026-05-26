<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_type', 20); // 'hotel' | 'property'
            $table->unsignedBigInteger('item_id');
            $table->timestamps();

            $table->unique(['user_id', 'item_type', 'item_id'], 'wishlist_unique_user_item');
            $table->index(['user_id', 'item_type']);
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
