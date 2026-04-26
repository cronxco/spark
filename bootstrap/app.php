<?php

use App\Http\Middleware\CacheApiResponse;
use App\Http\Middleware\EnsureIosMobileApiEnabled;
use App\Http\Middleware\ETag;
use App\Http\Middleware\SentryApiLogging;
use App\Http\Middleware\SentryMobileApiLogging;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration as SentryIntegration;
use Sentry\Laravel\Tracing\Middleware as SentryTracingMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Sanctum middleware on /broadcasting/auth — accepts both session-cookie web users
    // and bearer tokens (the iOS client subscribing to Reverb private channels).
    ->withBroadcasting(
        channels: __DIR__ . '/../routes/channels.php',
        attributes: ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Enable Sentry HTTP request tracing
        $middleware->append(SentryTracingMiddleware::class);

        // Register Sentry API logging middleware
        $middleware->alias([
            'sentry.api.logging' => SentryApiLogging::class,
            'sentry.mobile.logging' => SentryMobileApiLogging::class,
            'cache.api' => CacheApiResponse::class,
            'ios.enabled' => EnsureIosMobileApiEnabled::class,
            'etag' => ETag::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // Exclude webhook routes from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'webhook/*',
        ]);

        // Trust Jupiter (reverse proxy) to forward correct client IPs and protocol
        // Port 8080 is loopback-only on Titan, so '*' is safe; override via TRUSTED_PROXIES in .env
        // TrustProxies resolves config at request time for Octane compatibility.
        $middleware->prepend(TrustProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        SentryIntegration::handles($exceptions);
    })->create();
