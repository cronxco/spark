<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Integrations\Contracts\OAuthIntegrationPlugin;
use App\Integrations\PluginRegistry;
use App\Services\Api\ResourceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class IntegrationsController extends Controller
{
    public function __construct(private ResourceVersion $versions) {}

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
        )->header('ETag', $this->versions->etag($integration));
    }

    /**
     * POST /api/v1/mobile/integrations/{id}/sync
     *
     * Triggers an immediate fetch for the integration. Mirrors the logic
     * behind IntegrationApiController::trigger.
     */
    public function sync(Request $request, string $id): JsonResponse
    {
        $integration = $request->user()->integrations()->find($id);

        if (! $integration) {
            return response()->json(['message' => 'Integration not found.'], 404);
        }

        if ($integration->isPaused()) {
            return response()->json(['message' => 'Integration is paused.'], 422);
        }

        $jobsDispatched = (new DispatchIntegrationFetchJobs)->dispatch($integration);
        $integration->touch();

        return response()->json([
            'message' => 'Integration update triggered.',
            'jobs_dispatched' => $jobsDispatched,
        ])->header('ETag', $this->versions->etag($integration->fresh()));
    }

    /** Trigger all non-paused integrations for one service, matching MCP. */
    public function syncService(Request $request): JsonResponse
    {
        $data = $request->validate(['service' => ['required', 'string', 'max:100']]);
        $integrations = $request->user()->integrations()->where('service', $data['service'])->get();
        if ($integrations->isEmpty()) {
            return response()->json(['message' => 'No integrations found for service.'], 404);
        }

        $dispatcher = new DispatchIntegrationFetchJobs;
        $results = $integrations->map(function ($integration) use ($dispatcher): array {
            if ($integration->isPaused()) {
                return ['integration_id' => $integration->id, 'status' => 'skipped', 'reason' => 'paused', 'jobs_dispatched' => 0];
            }

            return ['integration_id' => $integration->id, 'status' => 'triggered', 'jobs_dispatched' => $dispatcher->dispatch($integration)];
        });

        return response()->json(['service' => $data['service'], 'integrations' => $results, 'total_jobs_dispatched' => $results->sum('jobs_dispatched')]);
    }

    /**
     * POST /api/v1/mobile/integrations/{id}/oauth/start
     *
     * Returns a provider OAuth URL for the app to open in
     * `ASWebAuthenticationSession`. The group is flagged as a mobile-initiated
     * reauth so the web OAuth callback redirects to the `spark://` custom
     * scheme (see IntegrationController::oauthCallback) to close the session.
     */
    public function oauthStart(Request $request, string $id): JsonResponse
    {
        $integration = $request->user()->integrations()->with('group')->find($id);

        if (! $integration) {
            return response()->json(['message' => 'Integration not found.'], 404);
        }

        $group = $integration->group;

        if (! $group) {
            return response()->json(['message' => 'Integration is not connected to an account group.'], 422);
        }

        $pluginClass = PluginRegistry::getPlugin($integration->service);

        if (! $pluginClass) {
            return response()->json(['message' => 'Unknown integration service.'], 422);
        }

        $plugin = new $pluginClass;

        if (! ($plugin instanceof OAuthIntegrationPlugin)) {
            return response()->json(['message' => 'This integration does not support re-authentication.'], 422);
        }

        // Flag the group so the shared web OAuth callback bridges back to the app.
        $metadata = $group->auth_metadata ?? [];
        $metadata['mobile_reauth_origin'] = true;
        $metadata['mobile_reauth_started_at'] = now()->toISOString();
        $group->auth_metadata = $metadata;
        $group->save();

        try {
            $url = $plugin->getOAuthUrl($group);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not start re-authentication.'], 422);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['message' => 'Could not start re-authentication.'], 422);
        }

        return response()->json(['url' => $url]);
    }
}
