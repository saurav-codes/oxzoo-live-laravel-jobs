<?php

namespace App\Zoo;

use Carbon\CarbonImmutable;

final class Info
{
    public const TRACE_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    public const BUILD_FILE = 'bootstrap/cache/zoo-build.json';

    public static function server(): string
    {
        foreach (explode('.', config('zoo.public_host')) as $label) {
            if (preg_match('/^s[0-9]+$/', $label)) {
                return $label;
            }
        }

        return 'local';
    }

    /** name, stack, server, release, env: the head of health and probe. */
    public static function head(): array
    {
        return [
            'name' => config('zoo.name'),
            'stack' => config('zoo.stack'),
            'server' => self::server(),
            'release' => config('zoo.release'),
            'env' => config('zoo.env'),
        ];
    }

    public static function health(): array
    {
        $started = self::startedAt();
        $build = ['runtime' => 'php '.PHP_VERSION];
        $file = base_path(self::BUILD_FILE);
        if (is_file($file)) {
            $stamp = json_decode((string) file_get_contents($file), true);
            if (is_array($stamp) && isset($stamp['built_at'])) {
                $build = ['built_at' => (string) $stamp['built_at']] + $build;
            }
        }

        return self::head() + [
            'uptime_s' => max(0, time() - $started),
            'started_at' => self::iso($started),
            'build' => $build,
        ];
    }

    public static function iso(int|float $unix): string
    {
        return CarbonImmutable::createFromTimestampUTC((int) $unix)->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * When the serving process started. PHP keeps no state between requests,
     * so on Linux it is read from /proc (FrankenPHP runs one process);
     * elsewhere (a laptop) it is this request.
     */
    private static function startedAt(): int
    {
        $stat = @file_get_contents('/proc/self/stat');
        $boot = @file_get_contents('/proc/stat');
        if ($stat !== false && $boot !== false && preg_match('/^btime (\d+)$/m', $boot, $b)) {
            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            $ticks = (int) ($fields[19] ?? 0);

            return (int) $b[1] + intdiv($ticks, 100);
        }

        return (int) ($_SERVER['REQUEST_TIME'] ?? time());
    }
}
