<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request, string $service, string $secret)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            abort(404);
        }
        
        $integration = Integration::where('service', $service)
            ->where('account_id', $secret)
            ->first();
            
        if (!$integration) {
            abort(404);
        }
        
        $plugin = new $pluginClass();
        
        try {
            $plugin->handleWebhook($request, $integration);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
