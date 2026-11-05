<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheApiResponse
{
    /**
     * Handle an incoming request.
     *
     * Caches GET API responses for authenticated users to reduce database load.
     *
     * @param  int  $ttl  Cache TTL in seconds (default: 60)
     */
    public function handle(Request $request, Closure $next, int $ttl = 60): Response
    {
        // Only cache GET requests
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        // Skip caching if explicitly requested
        if ($request->header('Cache-Control') === 'no-cache') {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        // Generate cache key from user ID, URL, and query parameters
        $cacheKey = sprintf(
            'api_response:%s:%s',
            $user->id,
            md5($request->fullUrl())
        );

        // Try to return cached response
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached['data'], $cached['status'])
                ->header('X-Cache', 'HIT');
        }

        // Execute request
        $response = $next($request);

        // Only cache successful JSON responses
        if ($response->isSuccessful() && $response->headers->get('Content-Type') === 'application/json') {
            $content = json_decode($response->getContent(), true);

            Cache::put($cacheKey, [
                'data' => $content,
                'status' => $response->getStatusCode(),
            ], $ttl);

            $response->headers->set('X-Cache', 'MISS');
        }

        return $response;
    }
}
