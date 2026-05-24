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
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keranjangs');
    }
};
