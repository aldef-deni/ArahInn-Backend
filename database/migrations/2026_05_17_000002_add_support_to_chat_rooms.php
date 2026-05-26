<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: cek dulu sebelum modify. Migration ini bisa di-run berkali-kali
        // walaupun sebagian sudah ke-apply dari run sebelumnya yang error.

        if (!Schema::hasColumn('chat_rooms', 'type')) {
            Schema::table('chat_rooms', function (Blueprint $table) {
                $table->string('type', 20)->default('booking')->after('id')->index();
            });
        }

        $indexes = collect(DB::select('SHOW INDEX FROM chat_rooms'))->pluck('Key_name')->toArray();
        $fks     = collect(DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_rooms'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        "))->pluck('CONSTRAINT_NAME')->toArray();

        if (in_array('chat_rooms_booking_id_foreign', $fks)) {
            DB::statement('ALTER TABLE chat_rooms DROP FOREIGN KEY chat_rooms_booking_id_foreign');
        }
        if (in_array('chat_rooms_hotel_id_foreign', $fks)) {
            DB::statement('ALTER TABLE chat_rooms DROP FOREIGN KEY chat_rooms_hotel_id_foreign');
        }
        if (in_array('chat_rooms_booking_id_unique', $indexes)) {
            DB::statement('ALTER TABLE chat_rooms DROP INDEX chat_rooms_booking_id_unique');
        }

        DB::statement('ALTER TABLE chat_rooms MODIFY booking_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE chat_rooms MODIFY hotel_id BIGINT UNSIGNED NULL');

        // Re-add unique + FKs (idempotent)
        $indexes2 = collect(DB::select('SHOW INDEX FROM chat_rooms'))->pluck('Key_name')->toArray();
        if (!in_array('chat_rooms_booking_id_unique', $indexes2)) {
            DB::statement('ALTER TABLE chat_rooms ADD UNIQUE chat_rooms_booking_id_unique (booking_id)');
        }
        if (!in_array('chat_rooms_user_id_type_index', $indexes2)) {
            DB::statement('ALTER TABLE chat_rooms ADD INDEX chat_rooms_user_id_type_index (user_id, type)');
        }

        $fks2 = collect(DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_rooms'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        "))->pluck('CONSTRAINT_NAME')->toArray();
        if (!in_array('chat_rooms_booking_id_foreign', $fks2)) {
            DB::statement('ALTER TABLE chat_rooms ADD CONSTRAINT chat_rooms_booking_id_foreign FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL');
        }
        if (!in_array('chat_rooms_hotel_id_foreign', $fks2)) {
            DB::statement('ALTER TABLE chat_rooms ADD CONSTRAINT chat_rooms_hotel_id_foreign FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        Schema::table('chat_rooms', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropForeign(['hotel_id']);
            $table->dropIndex(['user_id', 'type']);
            $table->dropColumn('type');
            // Restore non-null FKs
            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->foreign('hotel_id')->references('id')->on('hotels');
        });
    }
};
