<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SparkAbility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a token issued before the action-scope cutover (decision D-API-3)
 * back through token refresh. Such a token only holds the retired
 * `ios:read`/`ios:write` scopes, which no longer open any route. Answering
 * 401 rather than 403 makes the app refresh, and the refreshed token carries
 * the new capabilities, so nobody has to sign in again.
 */
class RequireMobileSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->holdsOnlyRetiredScopes($user)) {
            return response()->json([
                'message' => 'This session predates the current capabilities. Refresh the token and retry.',
                'reason' => 'session_upgrade_required',
            ], 401);
        }

        return $next($request);
    }

    private function holdsOnlyRetiredScopes(User $user): bool
    {
        if ($user->currentAccessToken() === null || $user->tokenCan(SparkAbility::MOBILE_SESSION_MARKER)) {
            return false;
        }

        foreach (SparkAbility::LEGACY_MOBILE_ABILITIES as $ability) {
            if ($user->tokenCan($ability)) {
                return true;
            }
        }

        return false;
    }
}
