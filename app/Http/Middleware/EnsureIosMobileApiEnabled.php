<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIosMobileApiEnabled
{
    /**
     * Gate the mobile API surface behind config('ios.mobile_api_enabled').
     *
     * 404 (not 403) so disabled endpoints are indistinguishable from
     * undeployed ones — avoids leaking the shape of the API pre-launch.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('ios.mobile_api_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
