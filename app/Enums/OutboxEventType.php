<?php

namespace App\Enums;

/**
 * Mendaftarkan event outbox yang mempunyai publisher handler di aplikasi.
 */
enum OutboxEventType: string
{
    case BUYER_CATALOG_PRODUCT_SYNC = 'buyer_catalog.product.sync';
    case BUYER_CATALOG_SELLER_SYNC = 'buyer_catalog.seller.sync';
}
