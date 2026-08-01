<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel alamats untuk alamat buyer dan seller, penerima, telepon, isi alamat, serta status
     * aktif. Setiap row dihubungkan ke user pemilik agar query selalu dapat dibatasi berdasarkan
     * account.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('alamats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('type', 20)->default('buyer')->nullable();
            $table->string('place', 50)->nullable();
            $table->string('name', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('alamat')->nullable();
            $table->tinyInteger('enable')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'type', 'enable']);
            $table->index(['user_id', 'type', 'created_at']);
        });
    }

    /**
     * Menghapus tabel alamats beserta seluruh constraint dan data di dalamnya untuk membatalkan
     * struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('alamats');
    }
};
