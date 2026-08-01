<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel users sebagai identity lokal aplikasi dengan UUID, profil dasar, email unik, dan
     * timestamp. Struktur ini menjadi induk bagi data buyer maupun seller yang disinkronkan dari
     * penyedia autentikasi.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('img', 191)->nullable();
            $table->string('name', 191);
            $table->string('jenis_kelamin', 20)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->string('email', 191)->unique();
            $table->string('phone', 20)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 191);
            $table->string('tfa', 20)->default('F');
            $table->string('account_type', 20)->default('buyer')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Menghapus tabel users beserta seluruh constraint dan data di dalamnya untuk membatalkan struktur
     * yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
