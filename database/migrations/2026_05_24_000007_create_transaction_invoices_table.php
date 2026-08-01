<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel transaction_invoices sebagai header pesanan buyer, snapshot alamat, metode
     * pembayaran, harga, status, dan masa berlaku. UUID invoice menjadi induk transaksi per seller
     * dalam satu checkout.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('transaction_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id_buyer')->nullable()->index();
            $table->text('alamat_buyer')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_slug', 50)->nullable();
            $table->string('payment_name', 100)->nullable();
            $table->string('payment_account')->nullable();
            $table->string('payment_reference')->nullable();
            $table->double('price')->nullable();
            $table->string('status', 30)->default('pending')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expired_at');
            $table->index(['payment_method', 'payment_slug', 'payment_account', 'status'], 'transaction_invoices_payment_lookup_index');
        });
    }

    /**
     * Menghapus tabel transaction_invoices beserta seluruh constraint dan data di dalamnya untuk
     * membatalkan struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_invoices');
    }
};
