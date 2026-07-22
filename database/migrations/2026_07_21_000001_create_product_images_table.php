<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Membuat penyimpanan multi-image dan menyalin referensi gambar produk lama.
     */
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->string('path');
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
            $table->index('product_id');
            $table->unique(['product_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_images ADD CONSTRAINT product_images_position_check CHECK (position BETWEEN 1 AND 5)');
        }

        // --- backfill product images - start - copy legacy paths without moving physical files
        DB::table('products')
            ->select(['id', 'img', 'created_at', 'updated_at'])
            ->whereNotNull('img')
            ->where('img', '<>', '')
            ->orderBy('id')
            ->chunk(500, function ($products) {
                foreach ($products as $product) {
                    DB::table('product_images')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'product_id' => $product->id,
                        'path' => $product->img,
                        'position' => 1,
                        'created_at' => $product->created_at ?? now(),
                        'updated_at' => $product->updated_at ?? now(),
                    ]);
                }
            });
        // --- backfill product images - end - copy legacy paths without moving physical files
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
