<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proof-of-life endpoint for the mobile API.
 *
 * Used by the iOS client to verify the full middleware stack (feature flag
 * + Sanctum + ability + ETag) is healthy after a token refresh, and by the
 * Phase 0 smoke tests to exercise the scaffold before the real endpoints
 * land.
 */
class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'user_id' => (string) $request->user()->getKey(),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
