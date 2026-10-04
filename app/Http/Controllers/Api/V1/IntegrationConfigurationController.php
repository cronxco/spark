<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Integrations\PluginRegistry;
use App\Services\Api\ResourceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IntegrationConfigurationController extends Controller
{
    public function configure(Request $request, string $id, ResourceVersion $versions): JsonResponse
    {
        $integration = $request->user()->integrations()->findOrFail($id);

        $pluginClass = PluginRegistry::getPlugin($integration->service);
        if (! $pluginClass || ! PluginRegistry::isAvailableTo($pluginClass, $request->user())) {
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

        $integration = $integration->fresh();

        return response()->json([
            'integration' => (new CompactIntegrationResource($integration))->resolve($request),
        ])->header('ETag', $versions->etag($integration));
    }

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
                case 'boolean':
                    $fieldRules[] = 'boolean';
                    break;
                case 'text':
                case 'textarea':
                case 'password':
                case 'select':
                case 'string':
                    $fieldRules[] = 'string';
                    break;
                case 'number':
                    $fieldRules[] = 'numeric';
                    break;
                case 'integer':
                    $fieldRules[] = 'integer';
                    break;
            }

            foreach (['min', 'max'] as $bound) {
                if (isset($config[$bound])) {
                    $fieldRules[] = "{$bound}:{$config[$bound]}";
                }
            }

            if (($config['type'] ?? null) === 'select' && isset($config['options'])) {
                $options = $config['options'];
                $fieldRules[] = Rule::in(array_is_list($options) ? $options : array_keys($options));
            }

            $rules[$field] = $fieldRules;
        }

        return $rules;
    }
}
