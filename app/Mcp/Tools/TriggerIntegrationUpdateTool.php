<?php

namespace App\Mcp\Tools;

use App\Actions\DispatchIntegrationFetchJobs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TriggerIntegrationUpdateTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Trigger an immediate update for one or more integrations, without waiting for the next scheduled pull.

        Provide either `integration_id` (for a specific instance) or `service` (to trigger all non-paused integrations for that service).
        The scheduled pull cycle is not affected — this is an extra, on-demand fetch.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $integrationId = $request->get('integration_id');
        $service = $request->get('service');

        if (! $integrationId && ! $service) {
            return Response::error('Provide either integration_id or service.');
        }

        $query = $user->integrations();

        if ($integrationId) {
            $integration = $query->find($integrationId);
            if (! $integration) {
                return Response::error("Integration {$integrationId} not found.");
            }
            $integrations = collect([$integration]);
        } else {
            $integrations = $query->where('service', $service)->get();
            if ($integrations->isEmpty()) {
                return Response::error("No integrations found for service '{$service}'.");
            }
        }

        $dispatcher = new DispatchIntegrationFetchJobs;
        $results = [];

        foreach ($integrations as $integration) {
            if ($integration->isPaused()) {
                $results[] = [
                    'integration_id' => $integration->id,
                    'service' => $integration->service,
                    'instance_type' => $integration->instance_type,
                    'status' => 'skipped',
                    'reason' => 'paused',
                    'jobs_dispatched' => 0,
                ];

                continue;
            }

            $jobsDispatched = $dispatcher->dispatch($integration);

            $results[] = [
                'integration_id' => $integration->id,
                'service' => $integration->service,
                'instance_type' => $integration->instance_type,
                'status' => 'triggered',
                'jobs_dispatched' => $jobsDispatched,
            ];
        }

        $triggered = collect($results)->where('status', 'triggered')->count();
        $totalJobs = collect($results)->sum('jobs_dispatched');

        return Response::text(json_encode([
            'triggered' => $triggered,
            'total_jobs_dispatched' => $totalJobs,
            'integrations' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('UUID of a specific integration instance to trigger. Takes precedence over service.'),
            'service' => $schema->string()
                ->description('Service name (e.g. "oura", "spotify", "monzo") to trigger all matching non-paused integrations.'),
        ];
    }
}
