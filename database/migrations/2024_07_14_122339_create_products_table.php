<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel products untuk kepemilikan seller, gambar cover legacy, nama, harga, dan stok.
     * Foreign key menghubungkan produk ke user seller dan timestamp mendukung pengurutan katalog.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id_seller')->nullable()->index('products_user_id_seller_index');
            $table->string('img')->nullable();
            $table->string('name')->nullable();
            $table->double('price')->nullable();
            $table->bigInteger('stock')->nullable();
            $table->timestamps();

            $table->index('updated_at');
        });
    }

    /**
     * Menghapus tabel products beserta seluruh constraint dan data di dalamnya untuk membatalkan
     * struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
