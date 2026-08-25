<?php

namespace App\Enums;

/**
 * Menentukan lifecycle publikasi setiap pesan transactional outbox.
 */
enum OutboxStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PUBLISHED = 'published';
    case FAILED = 'failed';
}
