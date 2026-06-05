<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

/**
 * Model AKUN TERPISAH: email sama boleh punya beberapa akun dengan role berbeda
 * (mis. 1 akun customer + 1 akun owner, email sama).
 * - Tambah kolom primary_role (role utama tiap akun).
 * - Ganti unique global email → composite unique (email, primary_role).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('primary_role', 40)->nullable()->after('email');
        });

        // Backfill primary_role dari role pertama tiap user (default 'user').
        User::query()->with('roles')->chunk(200, function ($users) {
            foreach ($users as $u) {
                $role = $u->getRoleNames()->first() ?? 'user';
                $u->updateQuietly(['primary_role' => $role]);
            }
        });

        // Pastikan tidak ada yang null sebelum jadi bagian unique index.
        User::whereNull('primary_role')->update(['primary_role' => 'user']);

        Schema::table('users', function (Blueprint $table) {
            // Lepas unique global email (nama index default: users_email_unique).
            $table->dropUnique(['email']);
            // Unique baru: kombinasi email + primary_role.
            $table->unique(['email', 'primary_role'], 'users_email_role_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_role_unique');
            $table->unique('email');
            $table->dropColumn('primary_role');
        });
    }
};
