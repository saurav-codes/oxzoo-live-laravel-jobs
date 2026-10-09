<?php

use App\Zoo\Info;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// A status page: recent ping-pong hops, the scheduler's heartbeat, the queue depth.
Route::get('/', function () {
    $error = null;
    $hops = $beat = $waiting = null;
    try {
        $hops = DB::table('zoo_hops')->orderByDesc('id')->limit(20)->get();
        $beat = DB::table('zoo_heartbeats')->where('name', 'scheduler')->value('at');
        $waiting = Redis::llen('queues:default');
    } catch (Throwable $e) {
        $error = class_basename($e).': '.Str::limit($e->getMessage(), 120);
    }

    return view('status', ['info' => Info::health(), 'hops' => $hops ?? [], 'beat' => $beat, 'waiting' => $waiting, 'error' => $error]);
});
