<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add optional map metadata without invalidating existing manual addresses.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::table('alamats', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('alamat');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('geoapify_place_id')->nullable()->after('longitude');
            $table->text('formatted_address')->nullable()->after('geoapify_place_id');
            $table->text('address_detail')->nullable()->after('formatted_address');
            $table->string('location_source', 20)->default('manual')->after('address_detail');
        });
    }

    /**
     * Remove map metadata while leaving the original address contract intact.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::table('alamats', function (Blueprint $table) {
            $table->dropColumn([
                'latitude',
                'longitude',
                'geoapify_place_id',
                'formatted_address',
                'address_detail',
                'location_source',
            ]);
        });
    }
};
