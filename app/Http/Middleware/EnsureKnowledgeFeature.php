<?php

namespace App\Http\Middleware;

use App\Services\Knowledge\KnowledgeFeature;
use Closure;
use Illuminate\Http\Request;

class EnsureKnowledgeFeature
{
    public function handle(Request $request, Closure $next)
    {
        KnowledgeFeature::ensure($request->user(), $request->isMethodSafe() ? 'reads' : 'writes');
        if (str_contains($request->route()->uri(), 'projects')) {
            KnowledgeFeature::ensure($request->user(), 'projects');
        }

        return $next($request);
    }
}
