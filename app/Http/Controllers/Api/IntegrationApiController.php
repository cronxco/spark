<?php

namespace App\Http\Controllers\Api;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Http\Controllers\Controller;
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IntegrationApiController extends Controller
{
    /**
     * Display a listing of the user's integrations with available plugins.
     */
    public function index(Request $request)
    {
        $plugins = PluginRegistry::getAllPlugins()->map(function ($pluginClass) {
            return [
                'identifier' => $pluginClass::getIdentifier(),
                'name' => $pluginClass::getDisplayName(),
                'description' => $pluginClass::getDescription(),
                'type' => $pluginClass::getServiceType(),
                'configuration_schema' => $pluginClass::getConfigurationSchema(),
            ];
        });

        $userIntegrations = $request->user()->integrations()->get();

        return response()->json([
            'plugins' => $plugins,
            'integrations' => $userIntegrations,
        ]);
    }

    /**
     * Display the specified integration.
     */
    public function show(Request $request, $integrationId)
    {
        $integration = $request->user()->integrations()->findOrFail($integrationId);

        return response()->json($integration);
    }

    /**
     * Configure the specified integration.
     */
    public function configure(Request $request, $integrationId)
    {
        $integration = $request->user()->integrations()->findOrFail($integrationId);

        $pluginClass = PluginRegistry::getPlugin($integration->service);
        if (! $pluginClass) {
            return response()->json(['error' => 'Plugin not found'], 404);
        }

        // Validate against the instance type's schema, as web configuration
        // does. The top-level schema is a different (and for several plugins
        // smaller) field set.
        $schema = $pluginClass::getInstanceTypes()[$integration->instance_type]['schema']
            ?? $pluginClass::getConfigurationSchema();

        // Build validation rules
        $rules = $this->buildValidationRules($schema);

        // Validate the configuration as it will be saved, so a request that
        // changes one field need not resend every required one.
        $current = $integration->configuration ?? [];
        $validated = Validator::make(array_merge($current, $request->all()), $rules)->validate();

        // Process array fields that come as comma-separated strings
        foreach ($validated as $field => $value) {
            if (($schema[$field]['type'] ?? null) === 'array' && is_string($value)) {
                $validated[$field] = array_filter(array_map('trim', explode(',', $value)));
            }
        }

        // Merge rather than replace: replacing dropped every key outside the
        // schema, including `paused`, schedule settings and stored API keys.
        $integration->update([
            'configuration' => array_merge($current, $validated),
        ]);

        return response()->json([
            'message' => 'Integration configured successfully',
            'integration' => $integration->fresh(),
        ]);
    }

    /**
     * Trigger an immediate update for the specified integration.
     */
    public function trigger(Request $request, string $integrationId): JsonResponse
    {
        $integration = $request->user()->integrations()->findOrFail($integrationId);

        if ($integration->isPaused()) {
            return response()->json(['error' => 'Integration is paused.'], 422);
        }

        $jobsDispatched = (new DispatchIntegrationFetchJobs)->dispatch($integration);

        if ($jobsDispatched === 0) {
            return response()->json([
                'error' => DispatchIntegrationFetchJobs::NOTHING_TO_DISPATCH,
                'code' => 'nothing_to_dispatch',
            ], 422);
        }

        return response()->json([
            'message' => 'Integration update triggered.',
            'integration_id' => $integration->id,
            'service' => $integration->service,
            'instance_type' => $integration->instance_type,
            'jobs_dispatched' => $jobsDispatched,
        ]);
    }

    /**
     * Remove the specified integration.
     */
    public function destroy(Request $request, $integrationId)
    {
        $integration = $request->user()->integrations()->findOrFail($integrationId);

        $integration->delete();

        return response()->json(['message' => 'Integration deleted successfully']);
    }

    /**
     * Build validation rules from configuration schema.
     */
    protected function buildValidationRules(array $schema): array
    {
        $rules = [];

        foreach ($schema as $field => $config) {
            $fieldRules = [];

            if ($config['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            switch ($config['type']) {
                case 'array':
                    $fieldRules[] = 'array';
                    break;
                case 'string':
                    $fieldRules[] = 'string';
                    break;
                case 'integer':
                    $fieldRules[] = 'integer';
                    break;
            }

            $rules[$field] = $fieldRules;
        }

        return $rules;
    }
}
