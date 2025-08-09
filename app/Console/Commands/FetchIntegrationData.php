<?php

namespace App\Console\Commands;

use App\Integrations\PluginRegistry;
use App\Jobs\ProcessIntegrationData;
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
                // Skip if currently processing
                if ($integration->isProcessing()) {
                    $this->line("Skipping integration {$integration->id} ({$integration->service}) - currently processing");
                    continue;
                }
                
                // Check if it's time to fetch data based on update frequency
                if ($integration->last_triggered_at && 
                    $integration->last_triggered_at->addMinutes($integration->update_frequency_minutes)->isFuture()) {
                    $this->line("Skipping integration {$integration->id} ({$integration->service}) - too soon since last update");
                    continue;
                }
                
                // Dispatch the job instead of processing directly
                ProcessIntegrationData::dispatch($integration);
                
                $this->line("Scheduled job for user: {$integration->user->name} (ID: {$integration->user_id}) - Service: {$integration->service}");
                $successCount++;
                
            } catch (\Exception $e) {
                $this->error("Failed to schedule job for integration {$integration->id} ({$integration->service}): " . $e->getMessage());
                $errorCount++;
            }
        }
        
        $this->info("Completed: {$successCount} successful, {$errorCount} failed");
        
        return $errorCount === 0 ? 0 : 1;
    }
}
