<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

/** Marks a key the probe waits for; the key expires on its own if the probe gave up. */
final class ProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $token) {}

    public static function key(string $token): string
    {
        return 'zoo:probe:job:'.$token;
    }

    public function handle(): void
    {
        Redis::setex(self::key($this->token), 30, '1');
    }
}
