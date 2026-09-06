<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Api\ResourceVersion;
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
    public function __construct(private readonly ResourceVersion $versions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
         * The strong user version, emitted explicitly.
         *
         * Routes guarded by `if-match:user` compare against ResourceVersion's
         * sha256 ETag. Without this header the generic `etag` middleware
         * supplies a weak `W/"md5(body)"` instead, which can never match — so
         * a client echoing what it read back as If-Match got 412 rather than
         * success, and had no way to obtain the value the middleware wanted.
         */
        return response()->json([
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->getTimezone(),
            'avatar_url' => null,
        ])->header('ETag', $this->versions->etag($user));
    }
}
