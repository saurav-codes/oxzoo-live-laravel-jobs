<?php

namespace App\Zoo;

use Illuminate\Support\Facades\DB;

final class Hops
{
    public static function record(string $trace, string $step, string $detail = ''): void
    {
        DB::table('zoo_hops')->insert([
            'trace' => $trace,
            'step' => $step,
            'detail' => mb_substr($detail, 0, 200),
            'at' => now('UTC'),
        ]);
    }

    /** @return list<array{at: string, step: string, detail: string}> */
    public static function of(string $trace): array
    {
        return DB::table('zoo_hops')->where('trace', $trace)->orderBy('id')->limit(50)->get()
            ->map(fn ($h) => ['at' => Info::iso(strtotime($h->at.' UTC')), 'step' => $h->step, 'detail' => $h->detail])
            ->all();
    }
}
