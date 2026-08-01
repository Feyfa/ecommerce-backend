<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel failed_jobs untuk menyimpan payload, koneksi, queue, exception, dan waktu
     * kegagalan job. Data tersebut memungkinkan kegagalan queue ditinjau atau dicoba ulang secara
     * operasional.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Menghapus tabel failed_jobs beserta seluruh constraint dan data di dalamnya untuk membatalkan
     * struktur yang dibuat oleh migration ini.
     *
     * @return void  Tidak mengembalikan nilai; perubahan diterapkan langsung pada schema database.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
