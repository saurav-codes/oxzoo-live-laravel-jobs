<?php

// Only the keys that differ from the framework defaults; Laravel merges the rest.
return [
    'name' => 'laravel-jobs',
    'env' => env('APP_ENV', 'production'),
    'debug' => false,
    'url' => env('PUBLIC_URL', 'http://localhost'),
    'key' => env('APP_KEY'),
];
