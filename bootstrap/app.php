<?php

use App\Http\Middleware\SentryApiLogging;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration as SentryIntegration;
use Sentry\Laravel\Tracing\Middleware as SentryTracingMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Enable Sentry HTTP request tracing
        $middleware->append(SentryTracingMiddleware::class);

        // Register Sentry API logging middleware
        $middleware->alias([
            'sentry.api.logging' => SentryApiLogging::class,
        ]);

        // Exclude webhook routes from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        SentryIntegration::handles($exceptions);
    })->create();
