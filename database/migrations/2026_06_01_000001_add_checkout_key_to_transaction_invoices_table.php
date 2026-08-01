<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan checkout_key unik pada transaction_invoices untuk mengidentifikasi snapshot checkout
     * yang sama. Kolom ini mendukung idempotensi agar retry pembayaran tidak membuat invoice duplikat.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::table('transaction_invoices', function (Blueprint $table) {
            $table->string('checkout_key', 64)->nullable()->unique()->after('user_id_buyer');
        });
    }

    /**
     * Menghapus unique checkout_key dari transaction_invoices untuk mengembalikan kontrak invoice
     * sebelum idempotensi checkout ditambahkan.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::table('transaction_invoices', function (Blueprint $table) {
            $table->dropUnique(['checkout_key']);
            $table->dropColumn('checkout_key');
        });
    }
};
