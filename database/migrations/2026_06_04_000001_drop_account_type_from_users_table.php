<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menghapus field account_type karena mode buyer/seller sudah disimpan per tab di frontend.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'account_type')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }

    /**
     * Mengembalikan field account_type saat rollback migration.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'account_type')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type', 20)->default('buyer')->nullable();
        });
    }
};
