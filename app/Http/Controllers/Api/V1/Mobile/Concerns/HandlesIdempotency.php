<?php

namespace App\Http\Controllers\Api\V1\Mobile\Concerns;

use App\Services\Api\IdempotentResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `Idempotency-Key` support for mutating POSTs that create something, so a
 * phone retrying on a bad connection can't create the thing twice.
 *
 * Optional, not required — a request without the header just runs normally.
 * When present, a repeat of the same key replays the first response rather
 * than re-running the handler, scoped per user and per call site so the same
 * key on two different endpoints can't collide.
 *
 * Routes guarded by `if-match` must use the `idempotent:<scope>` middleware
 * instead, ordered ahead of `if-match`: the first write moves the resource's
 * version on, so a retry carrying the original If-Match would otherwise be
 * refused with a 412 before this replay ever ran.
 */
trait HandlesIdempotency
{
    protected function idempotent(Request $request, string $scope, callable $handler): JsonResponse
    {
        $responses = app(IdempotentResponses::class);

        if ($replay = $responses->replay($request, $scope)) {
            return $replay;
        }

        $response = $handler();
        $responses->remember($request, $scope, $response);

        return $response;
    }
}
