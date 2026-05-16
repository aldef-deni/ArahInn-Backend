<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interior_designs', function (Blueprint $table) {
            $table->json('images')->nullable()->after('style');
            $table->json('videos')->nullable()->after('images');
        });

        // Migrasi data lama (single) ke format baru (array JSON)
        DB::table('interior_designs')->get()->each(function ($row) {
            DB::table('interior_designs')->where('id', $row->id)->update([
                'images' => $row->image ? json_encode([$row->image]) : json_encode([]),
                'videos' => $row->video ? json_encode([$row->video]) : json_encode([]),
            ]);
        });

        Schema::table('interior_designs', function (Blueprint $table) {
            $table->dropColumn(['image', 'video']);
        });
    }

    public function down(): void
    {
        Schema::table('interior_designs', function (Blueprint $table) {
            $table->string('image')->nullable()->after('style');
            $table->string('video')->nullable()->after('image');
            $table->dropColumn(['images', 'videos']);
        });
    }
};
