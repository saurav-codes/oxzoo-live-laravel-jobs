<?php

use App\Http\Middleware\BodyCap;
use App\Http\Middleware\ZooCors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Caddy on the same host terminates TLS.
        $middleware->trustProxies(at: '127.0.0.1');
        $middleware->prepend(ZooCors::class);
        $middleware->append(BodyCap::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('_zoo/*', 'api/*', 'webhooks/*'));
    })->create();
