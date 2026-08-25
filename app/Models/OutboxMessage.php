<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Menyimpan event durable yang akan dipublikasikan dari PostgreSQL ke transport asynchronous.
 */
class OutboxMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'published_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => OutboxStatus::class,
        'attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'locked_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
