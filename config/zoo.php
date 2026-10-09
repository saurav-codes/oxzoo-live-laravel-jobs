<?php

return [
    'name' => 'laravel-jobs',
    'stack' => 'Laravel 12 + MySQL (MariaDB) + Redis queue + scheduler',
    'release' => substr((string) env('OX_RELEASE', ''), 0, 12) ?: 'unknown',
    'env' => env('OX_ENV') ?: 'local',
    'public_host' => (string) env('PUBLIC_HOST', ''),
    'public_url' => (string) env('PUBLIC_URL', ''),
    'panel_origins' => (string) env('ZOO_PANEL_ORIGIN', ''),
    'webhook_secret' => (string) env('WEBHOOK_SECRET', ''),
    'rails_url' => rtrim((string) env('RAILS_URL', ''), '/'),
    // Who may sign requests to this project with WEBHOOK_SECRET.
    'callers' => ['rails-queue'],
];
