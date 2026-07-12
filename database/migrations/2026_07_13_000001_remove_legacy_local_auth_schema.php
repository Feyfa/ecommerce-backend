<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menghapus penyimpanan autentikasi Laravel lama karena Clerk sekarang
     * menjadi satu-satunya sumber autentikasi dan verifikasi identitas.
     */
    public function up(): void
    {
        /* step 1: hapus tabel reset password lokal yang tidak lagi digunakan */
        Schema::dropIfExists('password_reset_tokens');

        /* step 2: hapus column autentikasi lama dari user aplikasi */
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'remember_token',
                'tfa',
                'email_verified_at',
                'password',
            ]);
        });
    }

    /**
     * Mengembalikan struktur legacy untuk kebutuhan rollback. Nilai password
     * dibuat nullable karena credential lama tidak dapat dipulihkan.
     */
    public function down(): void
    {
        /* step 1: kembalikan column legacy tanpa mengarang credential user */
        Schema::table('users', function (Blueprint $table) {
            $table->rememberToken();
            $table->string('tfa', 20)->default('F');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 191)->nullable();
        });

        /* step 2: kembalikan tabel reset password bawaan Laravel */
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
