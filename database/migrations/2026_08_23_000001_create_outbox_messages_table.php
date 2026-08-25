<?php

use App\Enums\OutboxStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat penyimpanan transactional outbox generik untuk event yang harus dipublikasikan secara durable.
     *
     * @return void Perubahan diterapkan langsung pada schema database aktif.
     */
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->string('aggregate_type', 60);
            $table->string('aggregate_id', 191);
            $table->jsonb('payload')->default('{}');
            $table->string('status', 20)->default(OutboxStatus::PENDING->value);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at', 6)->nullable();
            $table->timestampTz('locked_at', 6)->nullable();
            $table->timestampTz('published_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz(6);

            $table->index(['status', 'available_at', 'created_at'], 'outbox_messages_due_index');
            $table->index(['status', 'locked_at'], 'outbox_messages_lock_index');
            $table->index(
                ['aggregate_type', 'aggregate_id', 'created_at'],
                'outbox_messages_aggregate_index',
            );
            $table->index('published_at', 'outbox_messages_published_index');
        });
    }

    /**
     * Menghapus tabel transactional outbox ketika migration dibatalkan.
     *
     * @return void Perubahan diterapkan langsung pada schema database aktif.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
