<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model append-only untuk menyimpan aktivitas penting milik user.
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'actor_clerk_user_id',
        'event',
        'category',
        'subject_type',
        'subject_id',
        'context',
        'ip_address',
        'user_agent',
        'clerk_session_id',
        'request_id',
        'idempotency_key',
        'occurred_at',
    ];

    protected $hidden = [
        'actor_clerk_user_id',
        'user_agent',
        'clerk_session_id',
        'request_id',
        'idempotency_key',
    ];

    protected $casts = [
        'event' => AuditEvent::class,
        'context' => 'array',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * Relasi actor dipakai untuk kebutuhan internal tanpa mengubah snapshot audit.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
