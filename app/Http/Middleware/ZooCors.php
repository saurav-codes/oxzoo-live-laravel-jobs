<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** CORS for the panel on /_zoo/health, probe, trace and chain (never verify). */
final class ZooCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! preg_match('#^/_zoo/(health|probe|trace/|chain/)#', '/'.ltrim($request->path(), '/'))) {
            return $next($request);
        }
        $origin = (string) $request->header('Origin', '');
        $allowed = array_filter(array_map('trim', explode(',', config('zoo.panel_origins'))));
        $ok = $origin !== '' && in_array($origin, $allowed, true);

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            if ($ok) {
                $response->headers->add([
                    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type',
                    'Access-Control-Max-Age' => '600',
                ]);
            }
        } else {
            $response = $next($request);
        }
        if ($ok) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
