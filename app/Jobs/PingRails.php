<?php

namespace App\Jobs;

use App\Zoo\Hops;
use App\Zoo\Signature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** ping-pong: the signed webhook to rails-queue, which acks back to /api/acks. */
final class PingRails implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public string $trace) {}

    public function handle(): void
    {
        $body = json_encode(['trace' => $this->trace, 'source' => 'laravel-jobs', 'event' => 'ping'], JSON_UNESCAPED_SLASHES);
        $secret = config('zoo.webhook_secret');
        $res = Http::timeout(8)->connectTimeout(5)
            ->withHeaders([
                Signature::HEADER => Signature::header($secret, config('zoo.name'), 'POST', '/webhooks/zoo', $body),
                'X-Zoo-Trace' => $this->trace,
            ])
            ->withBody($body, 'application/json')
            ->post(config('zoo.rails_url').'/webhooks/zoo');
        $res->throw();
        Hops::record($this->trace, 'webhook-sent', 'rails-queue answered '.$res->status());
    }
}
