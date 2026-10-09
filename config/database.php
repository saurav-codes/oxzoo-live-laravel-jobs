<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        // ox's mysql service is MariaDB 11.8 and provides MYSQL_URL.
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('MYSQL_URL'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ],
    ],

    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],

    'redis' => [
        // predis is pure PHP, so the worker's apt php-cli needs no redis extension.
        'client' => 'predis',
        'options' => ['prefix' => 'laravel-jobs:'],
        'default' => [
            'url' => env('REDIS_URL'),
            'timeout' => 5,
            'read_write_timeout' => 5,
        ],
    ],
];
