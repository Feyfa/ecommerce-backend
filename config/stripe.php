<?php 

return [
    'secret' => [
        'key' => env('STRIPE_SECRET_KEY')
    ],
    'published' => [
        'key' => env('STRIPE_PUBLISHED_KEY')
    ],
];