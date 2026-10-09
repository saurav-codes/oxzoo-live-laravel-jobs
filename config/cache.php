<?php

// The probe lock and the chain rate limit live in Redis.
return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'redis' => ['driver' => 'redis', 'connection' => 'default', 'lock_connection' => 'default'],
    ],
];
