<?php

namespace App\Http\Middleware;

use App\Support\AdminTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that are not scoped to one user are only usable while God Mode is on.
 */
class RequireGodMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AdminTenant::isGodMode()) {
            return redirect()->route('admin.god-mode.index')->with('error', 'Turn on God Mode to use this page.');
        }

        return $next($request);
    }
}
