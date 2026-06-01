<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transaction_invoices', function (Blueprint $table) {
            $table->string('checkout_key', 64)->nullable()->unique()->after('user_id_buyer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_invoices', function (Blueprint $table) {
            $table->dropUnique(['checkout_key']);
            $table->dropColumn('checkout_key');
        });
    }
};
