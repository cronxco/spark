<?php

namespace App\Http\Middleware;

use App\Support\SparkAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSparkAbility
{
    /**
     * Passes when the token holds any one of the listed capabilities, so
     * `spark.ability:bookmark:write,data:write` accepts either.
     */
    public function handle(Request $request, Closure $next, string $ability, string ...$alternatives): Response
    {
        $user = $request->user();
        $accepted = [$ability, ...$alternatives];

        if (! $user || ! collect($accepted)->contains(fn (string $candidate): bool => SparkAbility::allows($user, $candidate))) {
            return response()->json([
                'message' => 'Token lacks the required capability.',
                'required_ability' => $ability,
            ], 403);
        }

        return $next($request);
    }
}
