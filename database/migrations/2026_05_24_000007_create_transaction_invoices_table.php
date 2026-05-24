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
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_invoices');
    }
};
