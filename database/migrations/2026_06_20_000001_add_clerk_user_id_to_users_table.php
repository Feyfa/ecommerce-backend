<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tujuan migration ini untuk menambahkan identity bridge utama
     * antara user lokal Laravel dan user di Clerk.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'clerk_user_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('clerk_user_id', 191)->nullable()->unique();
        });
    }

    /**
     * Tujuan rollback ini untuk menghapus bridge Clerk dari tabel users
     * jika migration perlu dibatalkan.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('users', 'clerk_user_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('clerk_user_id');
        });
    }
};
