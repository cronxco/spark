<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the authenticated user's profile.
 *
 * Used by the iOS client on bootstrap (to cache user ID for Reverb channel
 * subscription) and on every Today view appear (to render the hero title).
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->getTimezone(),
            'avatar_url' => null,
        ]);
    }
}
