<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserve the buyer and seller pinpoint used when a transaction is created.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::table('transaction_invoices', function (Blueprint $table) {
            $table->decimal('alamat_buyer_latitude', 10, 7)->nullable()->after('alamat_buyer');
            $table->decimal('alamat_buyer_longitude', 10, 7)->nullable()->after('alamat_buyer_latitude');
            $table->string('alamat_buyer_location_source', 20)->default('manual')->after('alamat_buyer_longitude');
        });

        Schema::table('transaction_users', function (Blueprint $table) {
            $table->decimal('alamat_seller_latitude', 10, 7)->nullable()->after('alamat_seller');
            $table->decimal('alamat_seller_longitude', 10, 7)->nullable()->after('alamat_seller_latitude');
            $table->string('alamat_seller_location_source', 20)->default('manual')->after('alamat_seller_longitude');
        });
    }

    /**
     * Remove the location snapshots without touching the existing address text.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::table('transaction_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'alamat_buyer_latitude',
                'alamat_buyer_longitude',
                'alamat_buyer_location_source',
            ]);
        });

        Schema::table('transaction_users', function (Blueprint $table) {
            $table->dropColumn([
                'alamat_seller_latitude',
                'alamat_seller_longitude',
                'alamat_seller_location_source',
            ]);
        });
    }
};
