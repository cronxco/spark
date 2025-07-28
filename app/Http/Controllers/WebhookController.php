<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        // Verify webhook signature if plugin supports it
        $plugin = new $pluginClass();
        if (method_exists($plugin, 'verifyWebhookSignature')) {
            if (!$plugin->verifyWebhookSignature($request, $integration)) {
                abort(401, 'Invalid signature');
            }
        }

        $plugin = new $pluginClass();

        try {
            $plugin->handleWebhook($request, $integration);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            // Log the actual error but return generic message
            Log::error('Webhook handling failed', ['exception' => $e]);
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
