<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustProxies extends Middleware
{
    /** @var int */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Handle an incoming request.
     *
     * Proxies are resolved from config at request time to remain compatible
     * with Octane workers, which boot before the config repository is bound.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->proxies = config('app.trusted_proxies');

        return parent::handle($request, $next);
    }
}
