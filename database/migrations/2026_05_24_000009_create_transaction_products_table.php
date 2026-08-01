<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel transaction_products sebagai snapshot item produk pada transaksi seller. Harga dan
     * quantity disimpan terpisah dari model produk agar histori pesanan tidak berubah mengikuti
     * katalog.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('transaction_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id_seller')->nullable()->index();
            $table->uuid('user_id_buyer')->nullable()->index();
            $table->uuid('product_id')->nullable()->index();
            $table->uuid('transaction_user_id')->nullable()->index();
            $table->double('price')->nullable();
            $table->bigInteger('total')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Menghapus tabel transaction_products beserta seluruh constraint dan data di dalamnya untuk
     * membatalkan struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_products');
    }
};
