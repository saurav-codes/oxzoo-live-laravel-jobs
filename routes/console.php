<?php

use App\Zoo\Info;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Run by the ox cron every minute (php artisan schedule:run).
Schedule::call(function () {
    DB::table('zoo_heartbeats')->upsert([['name' => 'scheduler', 'at' => now('UTC')]], ['name'], ['at']);
})->everyMinute()->name('zoo-heartbeat');

// A build step: health reports when this release was built.
Artisan::command('zoo:stamp', function () {
    file_put_contents(base_path(Info::BUILD_FILE), json_encode(['built_at' => Info::iso(time())]));
    $this->info('wrote '.Info::BUILD_FILE);
})->purpose('Write the build time for /_zoo/health');
