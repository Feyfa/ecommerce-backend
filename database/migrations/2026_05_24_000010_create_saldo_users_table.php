<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel saldo_users sebagai balance income dan refund milik setiap user. Constraint user
     * unik memastikan setiap account hanya memiliki satu row saldo utama.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('saldo_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->unique();
            $table->double('saldo_income')->nullable();
            $table->double('saldo_refund')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Menghapus tabel saldo_users beserta seluruh constraint dan data di dalamnya untuk membatalkan
     * struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldo_users');
    }
};
