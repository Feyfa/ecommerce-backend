<?php 

return [
    'secret' => [
        'key' => env('STRIPE_SECRET_KEY')
    ],
    'published' => [
        'key' => env('STRIPE_PUBLISHED_KEY')
    ],
    'webhook' => [
        'key' => env('STRIPE_WEBHOOK_KEY')
    ],
    'app' => [
        'name' => env('STRIPE_APP_NAME')
    ]
];