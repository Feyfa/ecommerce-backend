<?php

$maxTotalHits = max(1, (int) env('BUYER_PRODUCT_SEARCH_MAX_TOTAL_HITS', 10000));

return [
    'index' => env('BUYER_PRODUCT_SEARCH_INDEX', 'buyer_products'),

    'per_page' => (int) env('BUYER_PRODUCT_SEARCH_PER_PAGE', 50),

    'max_per_page' => (int) env('BUYER_PRODUCT_SEARCH_MAX_PER_PAGE', 50),

    'max_total_hits' => $maxTotalHits,

    'settings' => [
        'searchableAttributes' => ['p_name', 'u_name'],
        'filterableAttributes' => [
            'seller_id',
            'is_purchasable',
            'p_price',
            'created_at_timestamp',
        ],
        'sortableAttributes' => [
            'id',
            'p_price',
            'p_name_sort',
            'created_at_timestamp',
            'updated_at_timestamp',
        ],
        'rankingRules' => [
            'words',
            'typo',
            'proximity',
            'attributeRank',
            'sort',
            'wordPosition',
            'exactness',
            'id:asc',
        ],
        'pagination' => [
            'maxTotalHits' => $maxTotalHits,
        ],
        'synonyms' => [
            'hp' => ['handphone', 'ponsel'],
            'handphone' => ['hp', 'ponsel'],
            'ponsel' => ['hp', 'handphone'],
            'spt' => ['sepatu'],
            'sepatu' => ['spt'],
            'lptp' => ['laptop'],
            'laptop' => ['lptp'],
            'tv' => ['televisi'],
            'televisi' => ['tv'],
            'powerbank' => ['power bank'],
            'power bank' => ['powerbank'],
            'charger' => ['cas'],
            'cas' => ['charger'],
            'sneakers' => ['snikers'],
            'snikers' => ['sneakers'],
        ],
    ],
];
