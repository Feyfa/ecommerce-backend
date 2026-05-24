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
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_products');
    }
};
