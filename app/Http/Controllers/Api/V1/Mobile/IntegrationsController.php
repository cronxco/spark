<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactIntegrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationsController extends Controller
{
    /**
     * GET /api/v1/mobile/integrations
     */
    public function index(Request $request): JsonResponse
    {
        $integrations = $request->user()
            ->integrations()
            ->orderBy('service')
            ->get();

        return response()->json([
            'data' => CompactIntegrationResource::collection($integrations)->resolve($request),
        ]);
    }

    /**
     * GET /api/v1/mobile/integrations/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $integration = $request->user()->integrations()->find($id);

        if (! $integration) {
            return response()->json(['message' => 'Integration not found.'], 404);
        }

        return response()->json(
            (new CompactIntegrationResource($integration))->resolve($request),
        );
    }
}
