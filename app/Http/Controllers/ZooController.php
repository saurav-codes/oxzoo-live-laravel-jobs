<?php

namespace App\Http\Controllers;

use App\Jobs\PingRails;
use App\Zoo\Hops;
use App\Zoo\Info;
use App\Zoo\Probe;
use App\Zoo\Signature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class ZooController
{
    public function health(): JsonResponse
    {
        return response()->json(Info::health());
    }

    public function probe(): JsonResponse
    {
        // One probe at a time on this server; a second one waits up to 5 s.
        $lock = fopen(storage_path('framework/zoo-probe.lock'), 'c');
        $deadline = microtime(true) + 5;
        while (! flock($lock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) > $deadline) {
                fclose($lock);

                return response()->json(['error' => 'probe busy'], 429);
            }
            usleep(100_000);
        }
        try {
            return response()->json((new Probe)->run());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function verify(Request $request): JsonResponse
    {
        $secret = config('zoo.webhook_secret');
        [$caller, $error] = Signature::verify($request, $secret, config('zoo.callers'));
        if ($error !== null) {
            return response()->json(['ok' => false, 'error' => $error], 401);
        }

        return response()->json([
            'ok' => true,
            'name' => config('zoo.name'),
            'public_url' => config('zoo.public_url'),
            'verified_by' => 'WEBHOOK_SECRET',
            'key_fp' => Signature::fp($secret),
            'caller' => $caller,
            'server' => Info::server(),
            'release' => config('zoo.release'),
        ]);
    }

    public function trace(string $id): JsonResponse
    {
        if (! preg_match(Info::TRACE_RE, $id)) {
            return response()->json(['error' => 'bad trace id'], 400);
        }
        $hops = Hops::of($id);

        return response()->json(['trace' => $id, 'found' => $hops !== [], 'hops' => $hops]);
    }

    public function chain(Request $request, string $chain): JsonResponse
    {
        if ($chain !== 'ping-pong') {
            return response()->json(['error' => 'unknown chain'], 404);
        }
        $trace = $request->json('trace');
        if (! is_string($trace) || ! preg_match(Info::TRACE_RE, $trace)) {
            return response()->json(['error' => 'trace must be a lowercase uuid'], 400);
        }
        if (config('zoo.webhook_secret') === '' || config('zoo.rails_url') === '') {
            return response()->json(['error' => 'WEBHOOK_SECRET and RAILS_URL must be set'], 503);
        }
        if (! RateLimiter::attempt('zoo-chain', 10, fn () => true, 60)) {
            return response()->json(['error' => 'rate limited: 10 starts per minute'], 429);
        }
        Hops::record($trace, 'queued', 'PingRails on the redis queue');
        PingRails::dispatch($trace);

        return response()->json(['trace' => $trace, 'started' => true], 202);
    }

    /** rails-queue's signed ack for a ping-pong trace. */
    public function ack(Request $request): JsonResponse
    {
        [$caller, $error] = Signature::verify($request, config('zoo.webhook_secret'), config('zoo.callers'));
        if ($error !== null) {
            return response()->json(['ok' => false, 'error' => $error], 401);
        }
        $trace = $request->json('trace');
        if (! is_string($trace) || ! preg_match(Info::TRACE_RE, $trace)) {
            return response()->json(['ok' => false, 'error' => 'trace must be a lowercase uuid'], 422);
        }
        Hops::record($trace, 'ack-received', "signed ack from {$caller}");

        return response()->json(['ok' => true, 'trace' => $trace]);
    }
}
