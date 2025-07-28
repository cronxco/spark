<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Http\Request;

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
        if (!$pluginClass) {
            return response()->json(['error' => 'Plugin not found'], 404);
        }
        
        $schema = $pluginClass::getConfigurationSchema();
        
        // Build validation rules
        $rules = $this->buildValidationRules($schema);
        
        $validated = $request->validate($rules);
        
        // Process array fields that come as comma-separated strings
        foreach ($validated as $field => $value) {
            if ($schema[$field]['type'] === 'array' && is_string($value)) {
                $validated[$field] = array_filter(array_map('trim', explode(',', $value)));
            }
        }
        
        $integration->update([
            'configuration' => $validated,
        ]);
        
        return response()->json([
            'message' => 'Integration configured successfully',
            'integration' => $integration->fresh(),
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