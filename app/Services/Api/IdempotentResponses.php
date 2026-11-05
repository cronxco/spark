<?php

namespace App\Services\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Stores and replays responses to requests carrying an `Idempotency-Key`,
 * so a phone retrying on a bad connection can't create the same thing twice.
 *
 * Optional, not required — a request without the header is never stored or
 * replayed. Replays are scoped per user and per call site so the same key on
 * two different endpoints can't collide.
 */
class IdempotentResponses
{
    /** How long a replayed response stays available. */
    private const TTL_HOURS = 24;

    /** The stored response for this request's key, or null when there isn't one. */
    public function replay(Request $request, string $scope): ?JsonResponse
    {
        $cacheKey = $this->cacheKey($request, $scope);
        $cached = $cacheKey ? Cache::get($cacheKey) : null;

        if (! is_array($cached)) {
            return null;
        }

        $response = response()->json($cached['body'], $cached['status']);
        if (isset($cached['etag'])) {
            $response->header('ETag', $cached['etag']);
        }

        return $response;
    }

    /** Keep a successful response so a retry with the same key replays it. */
    public function remember(Request $request, string $scope, JsonResponse $response): void
    {
        $cacheKey = $this->cacheKey($request, $scope);

        if (! $cacheKey || ! $response->isSuccessful()) {
            return;
        }

        Cache::put($cacheKey, [
            'body' => $response->getData(true),
            'status' => $response->getStatusCode(),
            'etag' => $response->headers->get('ETag'),
        ], now()->addHours(self::TTL_HOURS));
    }

    private function cacheKey(Request $request, string $scope): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return "idempotency:{$scope}:{$request->user()->id}:{$key}";
    }
}
