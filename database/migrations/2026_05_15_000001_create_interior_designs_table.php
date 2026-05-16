<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interior_designs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('style')->nullable();
            $table->string('image')->nullable();
            $table->string('video')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interior_designs');
    }
};
