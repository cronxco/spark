<?php

namespace App\Http\Middleware;

use App\Support\AdminTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes each admin page viewed in God Mode to the security activity log.
 */
class RecordGodModeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && AdminTenant::isGodMode()) {
            AdminTenant::recordAccess((string) ($request->route()?->getName() ?? $request->path()));
        }

        return $response;
    }
}
