<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_manager_owners', function (Blueprint $table) {
            $table->unsignedBigInteger('market_manager_id');
            $table->unsignedBigInteger('owner_id');
            $table->primary(['market_manager_id', 'owner_id']);
            $table->foreign('market_manager_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_manager_owners');
    }
};
