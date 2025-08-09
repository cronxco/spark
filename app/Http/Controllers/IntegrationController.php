<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\User;
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
        
        try {
            // Always create a new integration for OAuth flow
            $integration = $plugin->initialize($user);
            
            $oauthUrl = $plugin->getOAuthUrl($integration);
            
            Log::info('OAuth flow initiated', [
                'service' => $service,
                'user_id' => $user->id,
                'integration_id' => $integration->id,
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
        
        // Extract integration ID from state parameter
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
            $integrationId = $stateData['integration_id'] ?? null;
            
            if (!$integrationId) {
                Log::error('OAuth callback missing integration_id in state', [
                    'service' => $service,
                    'user_id' => $user->id,
                    'state_data' => $stateData,
                ]);
                return redirect()->route('integrations.index')
                    ->with('error', 'Invalid OAuth callback: missing integration ID');
            }
            
            // Get the specific integration from the state
            $integration = $user->integrations()
                ->where('id', $integrationId)
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
    

    

    

}
