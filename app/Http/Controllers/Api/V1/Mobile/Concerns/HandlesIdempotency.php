<?php

namespace App\Http\Controllers\Api\V1\Mobile\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * MR-17: `Idempotency-Key` support for mutating POSTs that create something,
 * so a phone retrying on a bad connection can't create the thing twice.
 *
 * Optional, not required — a request without the header just runs normally.
 * When present, a repeat of the same key replays the first response rather
 * than re-running the handler, scoped per user and per call site so the same
 * key on two different endpoints can't collide.
 */
trait HandlesIdempotency
{
    /** How long a replayed response stays available. */
    private const TTL_HOURS = 24;

    protected function idempotent(Request $request, string $scope, callable $handler): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return $handler();
        }

        $cacheKey = "idempotency:{$scope}:{$request->user()->id}:{$key}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return response()->json($cached['body'], $cached['status']);
        }

        $response = $handler();

        if ($response->isSuccessful()) {
            Cache::put($cacheKey, [
                'body' => $response->getData(true),
                'status' => $response->getStatusCode(),
            ], now()->addHours(self::TTL_HOURS));
        }

        return $response;
    }
}
