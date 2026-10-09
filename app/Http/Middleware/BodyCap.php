<?php

namespace App\Http\Middleware;

use App\Zoo\Signature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BodyCap
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->header('Content-Length', '0') > Signature::MAX_BODY || strlen($request->getContent()) > Signature::MAX_BODY) {
            return response()->json(['ok' => false, 'error' => 'body over 64 KB'], 413);
        }

        return $next($request);
    }
}
