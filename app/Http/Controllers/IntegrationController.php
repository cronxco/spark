<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

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
        
        try {
            // Create a new auth group and start OAuth
            if ($plugin instanceof \App\Integrations\Contracts\OAuthIntegrationPlugin) {
                $group = $plugin->initializeGroup($user);
                $oauthUrl = $plugin->getOAuthUrl($group);
            } else {
                throw new \Exception('Plugin does not support OAuth');
            }
            
            Log::info('OAuth flow initiated', [
                'service' => $service,
                'user_id' => $user->id,
                'group_id' => isset($group) ? $group->id : null,
                'oauth_url' => $oauthUrl,
            ]);
            
            // Ensure we're redirecting to an external URL (Spotify's OAuth)
            if (!filter_var($oauthUrl, FILTER_VALIDATE_URL)) {
                throw new \Exception('Invalid OAuth URL generated');
            }
            
            // Set proper headers to prevent CORS issues
            return redirect($oauthUrl)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        } catch (\Exception $e) {
            Log::error('OAuth flow failed', [
                'service' => $service,
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect()->route('integrations.index')
                ->with('error', 'Failed to initiate OAuth flow: ' . $e->getMessage());
        }
    }
    
    public function oauthCallback(Request $request, string $service)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        /** @var User $user */
        $user = Auth::user();
        
        // Extract group ID from state parameter
        $state = $request->get('state');
        if (!$state) {
            Log::error('OAuth callback missing state parameter', [
                'service' => $service,
                'user_id' => $user->id,
            ]);
            return redirect()->route('integrations.index')
                ->with('error', 'Invalid OAuth callback: missing state parameter');
        }
        
        try {
            $stateData = decrypt($state);
            $groupId = $stateData['group_id'] ?? null;
            
            if (!$groupId) {
                Log::error('OAuth callback missing group_id in state', [
                    'service' => $service,
                    'user_id' => $user->id,
                    'state_data' => $stateData,
                ]);
                return redirect()->route('integrations.index')
                    ->with('error', 'Invalid OAuth callback: missing group ID');
            }
            
            // Get the specific group from the state
            $group = IntegrationGroup::query()
                ->where('id', $groupId)
                ->where('user_id', $user->id)
                ->where('service', $service)
                ->firstOrFail();
                
        } catch (\Exception $e) {
            Log::error('OAuth callback state decryption failed', [
                'service' => $service,
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
            return redirect()->route('integrations.index')
                ->with('error', 'Invalid OAuth callback: state decryption failed');
        }
            
        try {
            if (method_exists($plugin, 'handleOAuthCallback')) {
                $plugin->handleOAuthCallback($request, $group);
            }
            // Redirect to onboarding to select instance types
            return redirect()->route('integrations.onboarding', ['group' => $group->id])
                ->with('success', 'Connected! Now choose what to track.');
        } catch (\Exception $e) {
            // Log the full exception details for debugging
            Log::error('OAuth callback failed', [
                'service' => $service,
                'user_id' => $user->id,
                'group_id' => $group->id ?? null,
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
            if (method_exists($plugin, 'initializeGroup')) {
                $group = $plugin->initializeGroup($user);
            } else {
                // Back-compat path
                $integration = $plugin->initialize($user);
                $group = IntegrationGroup::create([
                    'user_id' => $integration->user_id,
                    'service' => $integration->service,
                    'account_id' => $integration->account_id,
                    'access_token' => $integration->access_token,
                ]);
                $integration->update(['integration_group_id' => $group->id]);
            }

            return redirect()->route('integrations.onboarding', ['group' => $group->id])
                ->with('success', 'Integration initialized! Configure instances next.');
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
    
    public function onboarding(IntegrationGroup $group)
    {
        // Authorization
        if ((string) $group->user_id !== (string) Auth::id()) {
            abort(403);
        }
        $pluginClass = PluginRegistry::getPlugin($group->service);
        $pluginName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($group->service);
        $types = $pluginClass ? $pluginClass::getInstanceTypes() : [];

        return view('livewire.integrations.onboarding', [
            'group' => $group,
            'pluginName' => $pluginName,
            'types' => $types,
        ]);
    }
    
    public function storeInstances(Request $request, IntegrationGroup $group)
    {
        if ((string) $group->user_id !== (string) Auth::id()) {
            abort(403);
        }
        $pluginClass = PluginRegistry::getPlugin($group->service);
        if (!$pluginClass) {
            abort(404);
        }
        $plugin = new $pluginClass();
        // Allowed instance types from plugin
        $typesMeta = method_exists($pluginClass, 'getInstanceTypes') ? $pluginClass::getInstanceTypes() : [];
        $allowedTypes = array_keys($typesMeta);

        // Build validation rules
        $rules = [
            'types' => ['required','array','min:1'],
            'types.*' => ['string', Rule::in($allowedTypes)],
            'config' => ['array'],
        ];

        // Add per-field rules based on schema for each allowed type
        foreach ($allowedTypes as $typeKey) {
            $schema = $typesMeta[$typeKey]['schema'] ?? [];
            foreach ($schema as $field => $fieldConfig) {
                $fieldRules = [];
                $isRequired = (bool) ($fieldConfig['required'] ?? false);
                $fieldType = $fieldConfig['type'] ?? 'string';
                $min = $fieldConfig['min'] ?? null;

                $fieldRules[] = $isRequired ? 'required' : 'nullable';
                switch ($fieldType) {
                    case 'integer':
                        $fieldRules[] = 'integer';
                        if ($min !== null) {
                            $fieldRules[] = 'min:'.$min;
                        }
                        break;
                    case 'array':
                        $fieldRules[] = 'array';
                        break;
                    default:
                        $fieldRules[] = 'string';
                        break;
                }
                $rules["config.$typeKey.$field"] = $fieldRules;
            }
        }

        $data = $request->validate($rules);

        // Only keep config entries for selected types
        $selectedTypes = $data['types'];
        $data['config'] = Arr::only(($data['config'] ?? []), $selectedTypes);
        foreach ($data['types'] as $type) {
            $initial = $data['config'][$type] ?? [];
            // Extract frequency to instance column if present
            $frequency = $initial['update_frequency_minutes'] ?? null;
            if (array_key_exists('update_frequency_minutes', $initial)) {
                unset($initial['update_frequency_minutes']);
            }
            // Normalize schema-declared array fields that may arrive as strings
            $schemaForType = $typesMeta[$type]['schema'] ?? [];
            foreach ($schemaForType as $field => $fieldConfig) {
                if (($fieldConfig['type'] ?? null) === 'array' && isset($initial[$field]) && is_string($initial[$field])) {
                    $raw = $initial[$field];
                    $decoded = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $initial[$field] = array_values(array_filter(array_map('trim', $decoded)));
                    } else {
                        $parts = preg_split('/[,\n]/', $raw) ?: [];
                        $initial[$field] = array_values(array_filter(array_map('trim', $parts)));
                    }
                }
            }
            if (method_exists($plugin, 'createInstance')) {
                $instance = $plugin->createInstance($group, $type, $initial);
                if ($frequency !== null) {
                    $instance->update(['update_frequency_minutes' => (int) $frequency]);
                }
            }
        }
        return redirect()->route('integrations.index')
            ->with('success', 'Instances created successfully.');
    }
    

    

    

}
