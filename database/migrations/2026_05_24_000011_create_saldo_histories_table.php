<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel saldo_histories untuk mencatat sumber mutasi, jenis saldo, nominal, serta nilai
     * sebelum dan sesudah. Relasi opsional menghubungkan histori ke transaksi atau rekening pencairan.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('saldo_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('transaction_user_id')->nullable()->index();
            $table->uuid('payment_user_id')->nullable()->index();
            $table->string('type', 50)->nullable();
            $table->double('price')->nullable();
            $table->double('saldo_before')->nullable();
            $table->double('saldo_after')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Menghapus tabel saldo_histories beserta seluruh constraint dan data di dalamnya untuk
     * membatalkan struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldo_histories');
    }
};
