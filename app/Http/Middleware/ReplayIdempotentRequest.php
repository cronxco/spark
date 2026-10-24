<?php

namespace App\Http\Middleware;

use App\Services\Api\IdempotentResponses;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` replay for routes that also require If-Match.
 *
 * Must be listed before `if-match`. The first write moves the resource's
 * version on, so a phone retrying after a lost response still sends the
 * original If-Match; checking the replay first returns the first response
 * instead of a 412. Storing after `if-match` returns also means only a
 * committed write is ever replayed.
 */
class ReplayIdempotentRequest
{
    public function __construct(private IdempotentResponses $responses) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if ($replay = $this->responses->replay($request, $scope)) {
            return $replay;
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $this->responses->remember($request, $scope, $response);
        }

        return $response;
    }
}
