<?php

namespace App\Console\Commands;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Console\Command;

class FetchIntegrationData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:fetch {--service= : Fetch data for a specific service} {--force : Force update all integrations regardless of frequency}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch data from OAuth integrations that need updating based on their frequency settings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = $this->option('service');
        $force = $this->option('force');
        
        if ($service) {
            $this->info("Fetching data for {$service} integrations...");
            $query = Integration::where('service', $service)
                ->whereIn('service', PluginRegistry::getOAuthPlugins()->keys());
            
            if (!$force) {
                $query->needsUpdate();
            }
            
            $integrations = $query->get();
        } else {
            $this->info('Fetching data from OAuth integrations that need updating...');
            $query = Integration::whereHas('user')
                ->whereIn('service', PluginRegistry::getOAuthPlugins()->keys());
            
            if (!$force) {
                $query->needsUpdate();
            }
            
            $integrations = $query->get();
        }
        
        if ($integrations->isEmpty()) {
            $this->info('No integrations need updating at this time.');
            return 0;
        }
        
        $this->info("Found {$integrations->count()} integration(s) to update.");
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($integrations as $integration) {
            try {
                $pluginClass = PluginRegistry::getPlugin($integration->service);
                if (!$pluginClass) {
                    $this->error("Plugin not found for service: {$integration->service}");
                    $errorCount++;
                    continue;
                }
                
                $plugin = new $pluginClass();
                
                $this->info("Fetching data for {$integration->service} integration {$integration->id} (frequency: {$integration->update_frequency_minutes} minutes)");
                
                // Mark as triggered before processing
                $integration->markAsTriggered();
                
                $plugin->fetchData($integration);
                
                // Mark as successfully updated after processing
                $integration->markAsSuccessfullyUpdated();
                
                $successCount++;
                
            } catch (\Exception $e) {
                $this->error("Failed to fetch data for integration {$integration->id}: " . $e->getMessage());
                $errorCount++;
            }
        }
        
        $this->info("Completed: {$successCount} successful, {$errorCount} failed");
        
        return $errorCount === 0 ? 0 : 1;
    }
}
