<?php

namespace App\Http\Middleware;

use App\Support\AdminTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes each admin page viewed inside an operator context to the security
 * activity log (decision D-API-2).
 */
class RecordOperatorAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && AdminTenant::context() !== null) {
            AdminTenant::recordAccess((string) ($request->route()?->getName() ?? $request->path()));
        }

        return $response;
    }
}
