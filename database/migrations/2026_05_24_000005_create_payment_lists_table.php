<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat katalog payment_lists untuk mendefinisikan jenis, metode, slug, dan nama pembayaran.
     * Katalog ini menjadi referensi rekening user dan pilihan pembayaran yang tersedia.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('payment_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 50)->nullable();
            $table->string('method', 50)->nullable();
            $table->string('slug', 50)->nullable();
            $table->string('name', 100)->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent();

            $table->unique(['type', 'method', 'slug']);
            $table->index(['type', 'method']);
            $table->index(['type', 'slug']);
        });
    }

    /**
     * Menghapus tabel terkait beserta seluruh constraint dan data di dalamnya untuk membatalkan
     * struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_lists');
    }
};
