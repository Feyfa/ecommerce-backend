<?php

namespace App\Enums;

/**
 * Menentukan jenis aggregate yang menjadi target pesan outbox awal.
 */
enum OutboxAggregateType: string
{
    case PRODUCT = 'product';
    case SELLER = 'seller';
}
