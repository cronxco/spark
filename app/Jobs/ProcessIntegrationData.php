<?php

namespace App\Jobs;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIntegrationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $backoff = [60, 300, 600]; // Retry after 1, 5, 10 minutes

    protected Integration $integration;

    /**
     * Create a new job instance.
     */
    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $pluginClass = PluginRegistry::getPlugin($this->integration->service);
            if (!$pluginClass) {
                throw new \Exception("Plugin for service '{$this->integration->service}' not found");
            }

            $plugin = new $pluginClass();
            
            Log::info("Processing data for integration {$this->integration->id} ({$this->integration->service})");
            
            // Mark as triggered before processing
            $this->integration->markAsTriggered();
            
            $plugin->fetchData($this->integration);
            
            // Mark as successfully updated after processing
            $this->integration->markAsSuccessfullyUpdated();
            
            Log::info("Successfully processed data for integration {$this->integration->id} ({$this->integration->service})");
            
        } catch (\Exception $e) {
            Log::error("Failed to process data for integration {$this->integration->id} ({$this->integration->service})", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Integration data processing job failed permanently for integration {$this->integration->id} ({$this->integration->service})", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
