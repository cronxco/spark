<?php

namespace App\Http\Controllers;

use App\Integrations\PluginRegistry;
use App\Jobs\Webhook\AppleHealth\AppleHealthWebhookHook;
use App\Jobs\Webhook\Slack\SlackEventsHook;
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

        // Ensure downstream jobs receive the webhook secret header expected by validators
        // This mirrors the secret already validated via the route parameter
        $request->headers->set('x-webhook-secret', $secret);

        // Create plugin instance once and reuse it
        $plugin = new $pluginClass;

        $results = [];
        $hasFailures = false;

        foreach ($integrations as $integration) {
            try {
                // Dispatch the appropriate webhook job instead of processing inline
                $this->dispatchWebhookJob($service, $request, $integration);

                $results[$integration->id] = [
                    'status' => 'success',
                    'message' => 'Webhook job dispatched successfully',
                ];
            } catch (HttpExceptionInterface $e) {
                // Re-throw HttpExceptions so they result in proper HTTP status codes
                // This ensures abort(401) results in a 401 response, not 500
                throw $e;
            } catch (Exception $e) {
                // Log and track other exceptions for per-integration handling
                Log::error('Webhook job dispatch failed for integration', [
                    'integration_id' => $integration->id,
                    'service' => $service,
                    'exception' => $e->getMessage(),
                ]);

                $results[$integration->id] = [
                    'status' => 'error',
                    'message' => 'Webhook job dispatch failed',
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

    /**
     * Dispatch the appropriate webhook job for the given service
     */
    private function dispatchWebhookJob(string $service, Request $request, Integration $integration): void
    {
        $payload = $request->all();
        $headers = $request->headers->all();

        switch ($service) {
            case 'slack':
                SlackEventsHook::dispatch($payload, $headers, $integration);
                break;

            case 'apple_health':
                AppleHealthWebhookHook::dispatch($payload, $headers, $integration);
                break;

            default:
                // For services without specific webhook jobs, fall back to inline processing
                Log::warning("No webhook job found for service {$service}, falling back to inline processing", [
                    'integration_id' => $integration->id,
                    'service' => $service,
                ]);

                $pluginClass = PluginRegistry::getPlugin($service);
                if ($pluginClass) {
                    $plugin = new $pluginClass;
                    $plugin->handleWebhook($request, $integration);
                } else {
                    throw new Exception("Unknown service: {$service}");
                }
                break;
        }
    }
}
