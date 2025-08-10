<?php

namespace App\Jobs\Migrations;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIntegrationPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;
    public array $backoff = [60, 300, 600];

    protected Integration $integration;
    protected array $items;
    protected array $context;

    public function __construct(Integration $integration, array $items, array $context)
    {
        $this->integration = $integration;
        $this->items = $items;
        $this->context = $context;
        $this->onConnection('redis');
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        if (empty($this->items)) {
            return;
        }

        try {
            $service = $this->context['service'] ?? $this->integration->service;

            if ($service === 'oura') {
                $pluginClass = PluginRegistry::getPlugin('oura');
                (new $pluginClass())->processOuraMigrationItems(
                    $this->integration,
                    $this->context['instance_type'] ?? ($this->integration->instance_type ?: 'activity'),
                    $this->items
                );
                return;
            }

            if ($service === 'spotify') {
                $pluginClass = PluginRegistry::getPlugin('spotify');
                $plugin = new $pluginClass();
                foreach ($this->items as $item) {
                    $plugin->processRecentlyPlayedMigrationItem($this->integration, $item);
                }
                return;
            }

            if ($service === 'github') {
                $pluginClass = PluginRegistry::getPlugin('github');
                $plugin = new $pluginClass();
                foreach ($this->items as $event) {
                    $plugin->processEventPayload($this->integration, $event);
                }
                return;
            }

            Log::info('ProcessIntegrationPage: unsupported service, skipping', [
                'service' => $service,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessIntegrationPage failed', [
                'integration_id' => $this->integration->id,
                'service' => $this->context['service'] ?? $this->integration->service,
                'context' => $this->context,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}




