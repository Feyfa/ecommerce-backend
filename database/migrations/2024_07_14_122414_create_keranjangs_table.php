<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel keranjangs untuk menyimpan produk, buyer, seller, pilihan checkout, dan quantity.
     * Foreign key menjaga hubungan cart dengan produk serta kedua actor yang terlibat.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('keranjangs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id_seller');
            $table->uuid('user_id_buyer')->index('keranjangs_user_id_buyer_index');
            $table->uuid('product_id')->index('keranjangs_product_id_index');
            $table->boolean('checked')->default(false);
            $table->boolean('checkout')->nullable()->default(false);
            $table->bigInteger('total')->default(1);
            $table->timestamps();

            $table->index(['user_id_seller', 'user_id_buyer']);
            $table->index(['user_id_buyer', 'product_id']);
            $table->index(['user_id_buyer', 'created_at']);
        });
    }

    /**
     * Menghapus tabel keranjangs beserta seluruh constraint dan data di dalamnya untuk membatalkan
     * struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('keranjangs');
    }
};
