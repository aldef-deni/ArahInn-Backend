<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['room_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_prices');
    }
};
