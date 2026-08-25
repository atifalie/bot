<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Livewire polling har 5s chalti hai — session/CSRF expiry pe 419 aata
        // tha aur "page expired" popup baar-baar jhankta tha. Local demo
        // dashboard hai, is liye livewire/* ko CSRF se exempt kar do.
        $middleware->validateCsrfTokens(except: [
            'livewire*', // livewire/* + hashed update path (livewire-{hash}/update)
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // MySQL "gone away" → purge stale connection so next request reconnects fresh
        $exceptions->reportable(function (\Throwable $e) {
            if (str_contains($e->getMessage(), 'MySQL server has gone away')
                || str_contains($e->getMessage(), 'Lost connection to MySQL server')) {
                \Illuminate\Support\Facades\DB::purge('mysql');
            }
        });
    })->create();
