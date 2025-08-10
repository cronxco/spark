<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SentryApiLogging
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Set request context before calling next middleware to ensure it's available for error tracking
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($request) {
            $scope->setContext('api_request', [
                'url' => $request->url(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_id' => optional($request->user())->id,
            ]);
        });

        $response = $next($request);

        // Set response context after the request has been processed
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($response) {
            $scope->setContext('api_response', [
                'status' => $response->getStatusCode(),
            ]);
        });

        return $response;
    }
}
