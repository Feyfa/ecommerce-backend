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
        Schema::create('transaction_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id_seller')->nullable()->index();
            $table->uuid('user_id_buyer')->nullable()->index();
            $table->uuid('transaction_invoice_id')->nullable()->index();
            $table->string('transaction_number')->nullable()->index();
            $table->text('alamat_seller')->nullable();
            $table->string('kurir_type', 100)->nullable();
            $table->double('kurir_price')->nullable();
            $table->double('product_price')->nullable();
            $table->string('kurir_estimate', 100)->nullable();
            $table->string('noted', 200)->nullable();
            $table->string('status', 30)->default('approved_seller')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_users');
    }
};
