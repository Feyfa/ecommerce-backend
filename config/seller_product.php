<?php

$maxPerPage = max(1, (int) env('SELLER_PRODUCT_MAX_PER_PAGE', 50));

return [
    // Default tetap berada dalam batas yang sama dengan ukuran batch dari request.
    'per_page' => min($maxPerPage, max(1, (int) env('SELLER_PRODUCT_PER_PAGE', 50))),

    'max_per_page' => $maxPerPage,
];
