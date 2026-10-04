<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use Illuminate\Support\Str;
use App\Actions\DispatchIntegrationFetchJobs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\SetIntegrationPausedRequest;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Integrations\Contracts\OAuthIntegrationPlugin;
use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\Integration;
use App\Services\Api\ResourceVersion;
use App\Services\GoCardlessAccounts;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class IntegrationsController extends Controller
{
    private const RECENT_EVENT_LIMIT = 5;

    public function __construct(private ResourceVersion $versions) {}

    /**
     * GET /api/v1/mobile/integrations
     */
    public function index(Request $request): JsonResponse
    {
        $integrations = $request->user()
            ->integrations()
            ->addSelect(['last_event_time' => Event::select('time')
                ->whereColumn('integration_id', 'integrations.id')
                ->orderByDesc('time')
                ->limit(1),
            ])
            ->orderBy('service')
            ->get();

        return response()->json([
            'data' => CompactIntegrationResource::collection($integrations)->resolve($request),
        ]);
    }

    /**
     * GET /api/v1/mobile/integrations/{id}
     *
     * Shaped as the app's `IntegrationDetail`: the compact integration nested
     * under `integration`, plus sync state, recent events and whether the
     * integration can be re-authorised from the device.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $integration = $request->user()->integrations()->find($id);

        if (! $integration) {
            return response()->json(['message' => 'Integration not found.'], 404);
        }

        $recentEvents = Event::query()
            ->where('integration_id', $integration->id)
            ->with(['integration', 'actor', 'target', 'blocks', 'tags'])
            ->orderByDesc('time')
            ->limit(self::RECENT_EVENT_LIMIT)
            ->get();

        $integration->setAttribute('last_event_time', $recentEvents->first()?->time);

        $pluginClass = PluginRegistry::getPlugin($integration->service);
        $supportsReauth = $integration->integration_group_id !== null
            && $pluginClass !== null
            && is_subclass_of($pluginClass, OAuthIntegrationPlugin::class);

        return response()->json([
            'integration' => (new CompactIntegrationResource($integration))->resolve($request),
            'last_sync_at' => $integration->last_successful_update_at?->toIso8601String(),
            'coverage_percent' => null,
            'recent_events' => CompactEventResource::collection($recentEvents)->resolve($request),
            'domain' => $pluginClass ? $pluginClass::getDomain() : null,
            'status_message' => $this->statusMessage($integration),
            'supports_reauth' => $supportsReauth,
            'oauth_start_url' => $supportsReauth
                ? route('api.v1.mobile.integrations.oauth.start', $integration->id)
                : null,
        ])->header('ETag', $this->versions->etag($integration));
    }

    /**
     * POST /api/v1/mobile/integrations/{id}/sync
     *
     * Triggers an immediate fetch for the integration.
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

        if ($jobsDispatched === 0) {
            return response()->json([
                'message' => DispatchIntegrationFetchJobs::NOTHING_TO_DISPATCH,
                'code' => 'nothing_to_dispatch',
            ], 422);
        }

        $integration->touch();

        return response()->json([
            'message' => 'Integration update triggered.',
            'jobs_dispatched' => $jobsDispatched,
        ])->header('ETag', $this->versions->etag($integration->fresh()));
    }

    /**
     * POST /api/v1/mobile/integrations/{id}/pause
     *
     * Pauses or resumes scheduled fetches. Mirrors the web Updates page toggle.
     */
    public function setPaused(SetIntegrationPausedRequest $request, string $id): JsonResponse
    {
        $integration = $request->user()->integrations()->find($id);

        if (! $integration) {
            return response()->json(['message' => 'Integration not found.'], 404);
        }

        if ($integration->service === 'gocardless') {
            app(GoCardlessAccounts::class)->setPaused($integration, $request->boolean('paused'));
        } else {
            $configuration = $integration->configuration ?? [];
            $configuration['paused'] = $request->boolean('paused');
            $integration->update(['configuration' => $configuration]);
        }

        $integration = $integration->fresh();

        return response()->json(
            (new CompactIntegrationResource($integration))->resolve($request),
        )->header('ETag', $this->versions->etag($integration));
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

            $jobsDispatched = $dispatcher->dispatch($integration);

            return $jobsDispatched === 0
                ? ['integration_id' => $integration->id, 'status' => 'failed', 'reason' => 'nothing_to_dispatch', 'jobs_dispatched' => 0]
                : ['integration_id' => $integration->id, 'status' => 'triggered', 'jobs_dispatched' => $jobsDispatched];
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
        $metadata['mobile_reauth_attempt_id'] = (string) Str::uuid();
        $group->auth_metadata = $metadata;
        $group->save();

        try {
            $url = $plugin->getOAuthUrl($group);
        } catch (Throwable $e) {
            $currentMetadata = $group->fresh()->auth_metadata ?? [];
            if (($currentMetadata['mobile_reauth_attempt_id'] ?? null) === $metadata['mobile_reauth_attempt_id']) {
                unset($currentMetadata['mobile_reauth_origin'], $currentMetadata['mobile_reauth_started_at'], $currentMetadata['mobile_reauth_attempt_id']);
                $group->update(['auth_metadata' => $currentMetadata]);
            }

            return response()->json(['message' => 'Could not start re-authentication.'], 422);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['message' => 'Could not start re-authentication.'], 422);
        }

        return response()->json(['url' => $url, 'attempt_id' => $metadata['mobile_reauth_attempt_id']]);
    }

    /**
     * A sentence the app can show under the status pill, or null when the
     * status label says enough on its own.
     */
    private function statusMessage(Integration $integration): ?string
    {
        $status = $integration->statusKey($integration->last_event_time ? Carbon::parse($integration->last_event_time) : null);
        $runStatus = $integration->lastRun()['status'] ?? null;

        return match (true) {
            $status === 'paused' => 'Paused — Spark won\'t fetch until you resume it.',
            $status === 'processing' => null,
            $runStatus === 'failed' => 'The last update failed.',
            $runStatus === 'partial' => 'Some of the last update couldn\'t be processed.',
            $status === 'stale' => 'No new data recently. Nothing to fix unless you expected some.',
            $status === 'needs_update' => 'Overdue for an update.',
            default => null,
        };
    }
}
