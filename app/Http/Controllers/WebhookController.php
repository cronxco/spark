<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        $results = [];
        $hasFailures = false;

        foreach ($integrations as $integration) {
            try {
                // Let the plugin handle signature verification internally
                $plugin->handleWebhook($request, $integration);

                $results[$integration->id] = [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                ];
            } catch (HttpExceptionInterface $e) {
                // Re-throw HttpExceptions so they result in proper HTTP status codes
                // This ensures abort(401) results in a 401 response, not 500
                throw $e;
            } catch (Exception $e) {
                // Log and track other exceptions for per-integration handling
                Log::error('Webhook processing failed for integration', [
                    'integration_id' => $integration->id,
                    'service' => $service,
                    'exception' => $e->getMessage(),
                ]);

                $results[$integration->id] = [
                    'status' => 'error',
                    'message' => 'Webhook processing failed',
                    'error' => $e->getMessage(),
                ];
                $hasFailures = true;
            }
        }

        // Return appropriate response based on results
        if ($hasFailures) {
            // Some integrations failed - return 207 Multi-Status
            return response()->json([
                'status' => 'partial_success',
                'message' => 'Some webhooks processed successfully, others failed',
                'results' => $results,
            ], 207);
        }

        // All integrations succeeded
        return response()->json([
            'status' => 'success',
            'message' => 'All webhooks processed successfully',
            'results' => $results,
        ]);
    }
}
