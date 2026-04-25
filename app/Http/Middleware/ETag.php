<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ETag
{
    /**
     * Emit weak ETags on successful GET responses and short-circuit with 304
     * when the client's If-None-Match header matches. Saves bandwidth on
     * feed/briefing polls — the iOS client revalidates aggressively.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->method() !== 'GET' || ! $response->isSuccessful()) {
            return $response;
        }

        $etag = '"' . md5((string) $response->getContent()) . '"';
        $response->headers->set('ETag', $etag);

        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            $response->setContent('');
            $response->setStatusCode(304);
        }

        return $response;
    }
}
