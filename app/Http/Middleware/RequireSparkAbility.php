<?php

namespace App\Http\Middleware;

use App\Support\SparkAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSparkAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        if (! $user || ! SparkAbility::allows($user, $ability)) {
            return response()->json([
                'message' => 'Token lacks the required capability.',
                'required_ability' => $ability,
            ], 403);
        }

        return $next($request);
    }
}
