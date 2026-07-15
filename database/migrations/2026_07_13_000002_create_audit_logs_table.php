<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat append-only audit storage untuk aktivitas akun penting.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_clerk_user_id', 191)->nullable();
            $table->string('event', 100);
            $table->string('category', 50);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->jsonb('context')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('clerk_session_id', 191)->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['actor_user_id', 'occurred_at', 'id'], 'audit_logs_user_timeline_index');
            $table->index(['actor_user_id', 'event', 'occurred_at'], 'audit_logs_user_event_index');
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_index');
            $table->index('clerk_session_id');
            $table->index('request_id');
        });
    }

    /**
     * Menghapus audit storage ketika migration di-rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
