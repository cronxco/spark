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
    protected $signature = 'integrations:fetch {--service= : Fetch data for a specific service}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch data from all OAuth integrations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = $this->option('service');
        
        if ($service) {
            $this->info("Fetching data for {$service} integrations...");
            $integrations = Integration::where('service', $service)->get();
        } else {
            $this->info('Fetching data from all OAuth integrations...');
            $oauthIntegrations = Integration::whereHas('user')
                ->whereIn('service', PluginRegistry::getOAuthPlugins()->keys())
                ->get();
        }
        
        $integrations = $integrations ?? $oauthIntegrations;
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
                
                $this->info("Fetching data for {$integration->service} integration {$integration->id}");
                $plugin->fetchData($integration);
                
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
