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
        $response = $next($request);

        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($request, $response) {
            $scope->setContext('api_request', [
                'url' => $request->url(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_id' => optional($request->user())->id,
            ]);
            $scope->setContext('api_response', [
                'status' => $response->getStatusCode(),
            ]);
        });

        return $response;
    }
}
