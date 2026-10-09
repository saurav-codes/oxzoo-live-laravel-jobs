<?php

namespace App\Zoo;

use App\Jobs\ProbeJob;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/** The real round trips behind GET /_zoo/probe. */
final class Probe
{
    private const DEADLINE_S = 20;

    /** Where each variable a check needs ends up in the config. */
    private const CONFIG = [
        'MYSQL_URL' => 'database.connections.mysql.url',
        'REDIS_URL' => 'database.redis.default.url',
        'APP_KEY' => 'app.key',
        'RAILS_URL' => 'zoo.rails_url',
        'WEBHOOK_SECRET' => 'zoo.webhook_secret',
    ];

    private float $deadline;

    public function run(): array
    {
        $start = microtime(true);
        $this->deadline = $start + self::DEADLINE_S;
        $self = config('zoo.name').'@'.Info::server();
        $checks = [
            $this->check('mysql', 'MySQL write, read, delete', ['MYSQL_URL'], [$self], fn () => $this->mysql()),
            $this->check('redis', 'Redis SET/GET/DEL with TTL', ['REDIS_URL'], [$self], fn () => $this->redis()),
            $this->check('queue', 'Redis queue: dispatch a job, worker runs it', ['REDIS_URL'], [$self], fn () => $this->queue()),
            $this->check('scheduler', 'Scheduler heartbeat (schedule:run cron)', ['MYSQL_URL'], [$self], fn () => $this->scheduler()),
            $this->check('app-key', 'Encrypt and decrypt with APP_KEY', ['APP_KEY'], [$self], fn () => $this->appKey()),
            $this->check('peer:rails-queue', 'Signed call to rails-queue', ['RAILS_URL', 'WEBHOOK_SECRET'], [$self, 'rails-queue@s1'], fn () => $this->peer()),
        ];

        return Info::head() + [
            'ok' => ! in_array(false, array_column($checks, 'ok'), true),
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'at' => Info::iso(time()),
            'checks' => $checks,
            'vars' => self::vars(),
        ];
    }

    public static function vars(): array
    {
        $secret = config('zoo.webhook_secret');
        $rails = config('zoo.rails_url');

        return [
            $secret === '' ? ['name' => 'WEBHOOK_SECRET', 'missing' => true, 'role' => 'verifies']
                : ['name' => 'WEBHOOK_SECRET', 'fp' => Signature::fp($secret), 'role' => 'verifies'],
            $rails === '' ? ['name' => 'RAILS_URL', 'missing' => true, 'role' => 'url', 'peer' => 'rails-queue']
                : ['name' => 'RAILS_URL', 'value' => $rails, 'role' => 'url', 'peer' => 'rails-queue'],
            ['name' => 'ZOO_PANEL_ORIGIN', 'value' => config('zoo.panel_origins'), 'role' => 'plain'],
        ];
    }

    /** @param  list<string>  $env */
    private function check(string $id, string $label, array $env, array $hops, callable $fn): array
    {
        $t = microtime(true);
        $out = ['id' => $id, 'label' => $label];
        try {
            foreach ($env as $name) {
                if ((string) config(self::CONFIG[$name]) === '') {
                    throw new \RuntimeException("{$name} is not set");
                }
            }
            if (microtime(true) > $this->deadline) {
                throw new \RuntimeException('probe deadline of 20 s passed');
            }
            $out += ['ok' => true, 'detail' => $fn()];
        } catch (Throwable $e) {
            $out += ['ok' => false, 'error' => Str::limit($e->getMessage(), 200)];
        }

        return $out + ['ms' => (int) round((microtime(true) - $t) * 1000), 'env' => $env, 'hops' => $hops];
    }

    private function mysql(): string
    {
        $token = Str::random(24);
        $id = DB::table('zoo_probe')->insertGetId(['token' => $token, 'created_at' => now('UTC')]);
        try {
            $back = DB::table('zoo_probe')->where('id', $id)->value('token');
            if ($back !== $token) {
                throw new \RuntimeException('read back a different value');
            }
        } finally {
            DB::table('zoo_probe')->where('id', $id)->delete();
        }

        return 'zoo_probe row round trip, server '.DB::scalar('select version()');
    }

    private function redis(): string
    {
        $key = 'zoo:probe:'.Str::random(16);
        $value = Str::random(24);
        Redis::setex($key, 30, $value);
        $back = Redis::get($key);
        $ttl = Redis::ttl($key);
        Redis::del($key);
        if ($back !== $value || $ttl <= 0) {
            throw new \RuntimeException('read back a different value or no TTL');
        }

        return "key round trip, ttl {$ttl} s";
    }

    private function queue(): string
    {
        $token = Str::random(24);
        $t = microtime(true);
        ProbeJob::dispatch($token);
        $key = ProbeJob::key($token);
        while (microtime(true) - $t < 5) {
            if (Redis::get($key) === '1') {
                Redis::del($key);

                return 'probe job ran in '.(int) round((microtime(true) - $t) * 1000).' ms, '.Redis::llen('queues:default').' waiting';
            }
            usleep(50_000);
        }

        throw new \RuntimeException('no worker ran the probe job within 5000 ms (is queue:work running?)');
    }

    private function scheduler(): string
    {
        $at = DB::table('zoo_heartbeats')->where('name', 'scheduler')->value('at');
        if ($at === null) {
            throw new \RuntimeException('no heartbeat yet: schedule:run has not run');
        }
        $age = time() - strtotime($at.' UTC');
        if ($age > 150) {
            throw new \RuntimeException("last heartbeat {$age} s ago (the cron runs every minute)");
        }

        return "last heartbeat {$age} s ago";
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($raw) || ! Encrypter::supported($raw, 'aes-256-cbc')) {
            throw new \RuntimeException('APP_KEY is not 32 bytes: make one with php artisan key:generate --show');
        }
        $plain = Str::random(16);
        if (decrypt(encrypt($plain)) !== $plain) {
            throw new \RuntimeException('decrypt gave a different value');
        }

        return 'aes-256-cbc round trip';
    }

    private function peer(): string
    {
        $secret = config('zoo.webhook_secret');
        $url = config('zoo.rails_url');
        $res = Http::timeout(8)->connectTimeout(5)
            ->withHeaders([Signature::HEADER => Signature::header($secret, config('zoo.name'), 'GET', '/_zoo/verify', '')])
            ->get($url.'/_zoo/verify');
        $body = $res->json() ?? [];
        $problems = array_filter([
            $res->status() !== 200 ? 'status '.$res->status().(isset($body['error']) ? ' ('.Str::limit((string) $body['error'], 60).')' : '') : null,
            ($body['name'] ?? null) !== 'rails-queue' ? 'name is not rails-queue' : null,
            ($body['key_fp'] ?? null) !== Signature::fp($secret) ? 'key_fp differs from ours' : null,
            ($body['public_url'] ?? null) !== $url ? 'public_url differs from RAILS_URL' : null,
        ]);
        if ($problems) {
            throw new \RuntimeException(implode(', ', $problems));
        }

        return 'verified by rails-queue, key_fp '.$body['key_fp'];
    }
}
