<?php

use App\Http\Controllers\ZooController;
use Illuminate\Support\Facades\Route;

Route::get('/_zoo/health', [ZooController::class, 'health']);
Route::get('/_zoo/probe', [ZooController::class, 'probe']);
Route::get('/_zoo/verify', [ZooController::class, 'verify']);
Route::get('/_zoo/trace/{id}', [ZooController::class, 'trace']);
Route::post('/_zoo/chain/{chain}', [ZooController::class, 'chain']);
Route::post('/api/acks', [ZooController::class, 'ack']);
