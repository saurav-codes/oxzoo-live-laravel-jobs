<?php

namespace App\Zoo;

use Illuminate\Http\Request;

/** zoo-sig v1: HMAC-SHA256 over "<t>.<METHOD>.<path with query>.<hex sha256 of body>". */
final class Signature
{
    public const HEADER = 'X-Zoo-Signature';
    public const MAX_SKEW = 300;
    public const MAX_BODY = 65536;

    public static function sign(string $key, int $t, string $method, string $path, string $body): string
    {
        $message = $t.'.'.strtoupper($method).'.'.$path.'.'.hash('sha256', $body);

        return hash_hmac('sha256', $message, $key);
    }

    public static function header(string $key, string $caller, string $method, string $path, string $body, ?int $t = null): string
    {
        $t ??= time();

        return "t={$t},caller={$caller},sig=".self::sign($key, $t, $method, $path, $body);
    }

    /** Last 4 hex characters of sha256(value). */
    public static function fp(string $value): string
    {
        return substr(hash('sha256', $value), -4);
    }

    /**
     * Checks a request's signature. Returns [caller, null] when valid, else
     * [null, reason] with one of the contract's reasons.
     *
     * @param  list<string>  $callers
     * @return array{0: ?string, 1: ?string}
     */
    public static function verify(Request $request, string $key, array $callers, ?int $now = null): array
    {
        $header = (string) $request->header(self::HEADER, '');
        if ($header === '') {
            return [null, 'missing signature'];
        }
        if (! preg_match('/^t=([0-9]{1,12}),caller=([a-z0-9-]{1,64}),sig=([0-9a-f]{64})$/', $header, $m)) {
            return [null, 'bad format'];
        }
        [, $t, $caller, $sig] = $m;
        if (abs(($now ?? time()) - (int) $t) > self::MAX_SKEW) {
            return [null, 'expired'];
        }
        if (! in_array($caller, $callers, true)) {
            return [null, 'unknown caller'];
        }
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY) {
            return [null, 'bad format'];
        }
        $want = self::sign($key, (int) $t, $request->getMethod(), $request->getRequestUri(), $body);
        if ($key === '' || ! hash_equals($want, $sig)) {
            return [null, 'bad signature'];
        }

        return [$caller, null];
    }
}
