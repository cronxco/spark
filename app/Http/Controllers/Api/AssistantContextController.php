<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\AssistantContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantContextController extends Controller
{
    /**
     * Generate assistant context JSON for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get user's Flint integration
        $integration = Integration::where('user_id', $user->id)
            ->where('service', 'flint')
            ->first();

        if (! $integration) {
            return response()->json([
                'error' => 'Flint integration not configured',
            ], 404);
        }

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $integration);

        return response()->json($context);
    }
}
