<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interior_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('no_hp', 20);
            $table->string('proyek');
            $table->string('desain_referensi')->nullable();
            $table->string('status', 20)->default('new'); // new, contacted, closed
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interior_inquiries');
    }
};
