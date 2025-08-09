<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class IntegrationController extends Controller
{

    
    public function oauth(string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        $user = Auth::user();
        
        // Always create a new integration for OAuth flow
        $integration = $plugin->initialize($user);
        
        $oauthUrl = $plugin->getOAuthUrl($integration);
        
        return redirect($oauthUrl);
    }
    
    public function oauthCallback(Request $request, string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        $user = Auth::user();
        
        // Get the most recent integration for this service
        $integration = $user->integrations()
            ->where('service', $service)
            ->latest()
            ->firstOrFail();
            
        try {
            $plugin->handleOAuthCallback($request, $integration);
            
            return redirect()->route('integrations.index')
                ->with('success', 'Integration connected successfully!');
        } catch (\Exception $e) {
            // Log the full exception details for debugging
            Log::error('OAuth callback failed', [
                'service' => $service,
                'user_id' => $user->id,
                'integration_id' => $integration->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->route('integrations.index')
                ->with('error', 'Failed to connect integration. Please try again or contact support if the problem persists.');
        }
    }
    
    public function initialize(string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        $user = Auth::user();
        
        try {
            $integration = $plugin->initialize($user);
            
            return redirect()->route('integrations.index')
                ->with('success', 'Integration initialized successfully! Webhook URL: ' . route('webhook.handle', ['service' => $service, 'secret' => $integration->account_id]));
        } catch (\Exception $e) {
            // Log the full exception details for debugging
            Log::error('Integration initialization failed', [
                'service' => $service,
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->route('integrations.index')
                ->with('error', 'Failed to initialize integration. Please try again or contact support if the problem persists.');
        }
    }
    
    public function configure(Integration $integration)
    {
        // Ensure user owns this integration - convert both to strings for comparison
        if ((string) $integration->user_id !== (string) Auth::id()) {
            abort(403);
        }
        
        $pluginClass = PluginRegistry::getPlugin($integration->service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $schema = $pluginClass::getConfigurationSchema();
        
        return view('integrations.configure', compact('integration', 'schema'));
    }
    
    public function updateConfiguration(Request $request, Integration $integration)
    {
        // Ensure user owns this integration - convert both to strings for comparison
        if ((string) $integration->user_id !== (string) Auth::id()) {
            abort(403);
        }
        
        $pluginClass = PluginRegistry::getPlugin($integration->service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $schema = $pluginClass::getConfigurationSchema();
        
        $validated = $request->validate($this->buildValidationRules($schema));
        
        // Process array fields that come as comma-separated strings
        foreach ($validated as $field => $value) {
            if ($schema[$field]['type'] === 'array' && is_string($value)) {
                $validated[$field] = array_filter(array_map('trim', explode(',', $value)));
            }
        }
        
        // Extract update frequency if it exists in the configuration
        $updateFrequency = $validated['update_frequency_minutes'] ?? 15;
        unset($validated['update_frequency_minutes']);
        
        $integration->update([
            'configuration' => $validated,
            'update_frequency_minutes' => $updateFrequency,
        ]);
        
        return redirect()->route('integrations.index')
            ->with('success', 'Integration configured successfully!');
    }
    
    public function disconnect(Integration $integration)
    {
        // Ensure user owns this integration - convert both to strings for comparison
        if ((string) $integration->user_id !== (string) Auth::id()) {
            abort(403);
        }
        
        $integration->delete();
        
        return redirect()->route('integrations.index')
            ->with('success', 'Integration disconnected successfully!');
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
                case 'string':
                    $fieldRules[] = 'string';
                    break;
                case 'integer':
                    $fieldRules[] = 'integer';
                    if (isset($config['min'])) {
                        $fieldRules[] = "min:{$config['min']}";
                    }
                    break;
            }
            
            $rules[$field] = $fieldRules;
        }
        
        return $rules;
    }
}
