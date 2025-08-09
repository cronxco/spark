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
    

    

    

}
