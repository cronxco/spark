<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\IntegrationGroup;
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

        // Find the integration group first (webhooks are sent to group-level endpoints)
        $group = IntegrationGroup::where('service', $service)
            ->where('account_id', $secret)
            ->first();

        if (! $group) {
            abort(404);
        }

        // Get any integration from this group (they all share the same webhook endpoint)
        $integration = Integration::where('integration_group_id', $group->id)->first();

        if (! $integration) {
            abort(404);
        }

        // Create plugin instance once and reuse it
        $plugin = new $pluginClass;

        try {
            // Let the plugin handle the webhook (including signature verification if needed)
            $plugin->handleWebhook($request, $integration);

            return response()->json(['status' => 'success']);
        } catch (Exception $e) {
            // Log the actual error but return generic message
            Log::error('Webhook handling failed', ['exception' => $e]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
