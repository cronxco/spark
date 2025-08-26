<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, string $service, string $secret)
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (! $pluginClass) {
            abort(404);
        }

        $integrations = Integration::where('service', $service)
            ->where('account_id', $secret)
            ->get();

        if ($integrations->isEmpty()) {
            abort(404);
        }

        // Create plugin instance once and reuse it
        $plugin = new $pluginClass;

        try {
            foreach ($integrations as $integration) {
                // Verify webhook signature if plugin supports it per instance
                if (method_exists($plugin, 'verifyWebhookSignature')) {
                    if (! $plugin->verifyWebhookSignature($request, $integration)) {
                        abort(401, 'Invalid signature');
                    }
                }
                $plugin->handleWebhook($request, $integration);
            }

            return response()->json(['status' => 'success']);
        } catch (Exception $e) {
            Log::error('Webhook handling failed', ['exception' => $e]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
