<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->enum('type', ['banner', 'email', 'push', 'popup'])->default('banner');
            $table->enum('target', ['all', 'new_user', 'loyal', 'inactive'])->default('all');
            $table->enum('status', ['draft', 'active', 'inactive', 'ended'])->default('draft');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('budget', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['status', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
