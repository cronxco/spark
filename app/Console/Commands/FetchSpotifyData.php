<?php

namespace App\Console\Commands;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Console\Command;

class FetchSpotifyData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spotify:fetch {--user= : Fetch data for a specific user ID} {--force : Force update regardless of frequency}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch Spotify listening data and create events for track plays';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->option('user');
        $force = $this->option('force');
        
        $query = Integration::where('service', 'spotify')
            ->whereHas('user');
            
        if ($userId) {
            $query->where('user_id', $userId);
        }
        
        if (!$force) {
            // For Spotify, we want to check more frequently than other integrations
            // Check if it's been more than 30 seconds since last update
            $query->where(function ($q) {
                $q->whereNull('last_triggered_at')
                  ->orWhere('last_triggered_at', '<=', now()->subSeconds(30));
            });
        }
        
        $integrations = $query->get();
        
        if ($integrations->isEmpty()) {
            $this->info('No Spotify integrations need updating at this time.');
            return 0;
        }
        
        $this->info("Found {$integrations->count()} Spotify integration(s) to update.");
        
        $successCount = 0;
        $errorCount = 0;
        $eventsCreated = 0;
        
        foreach ($integrations as $integration) {
            try {
                $pluginClass = PluginRegistry::getPlugin('spotify');
                if (!$pluginClass) {
                    $this->error("Spotify plugin not found");
                    $errorCount++;
                    continue;
                }
                
                $plugin = new $pluginClass();
                
                $this->info("Fetching Spotify data for user {$integration->user->name} (ID: {$integration->user_id})");
                
                // Mark as triggered before processing
                $integration->markAsTriggered();
                
                // Count events before processing
                $eventsBefore = \App\Models\Event::where('integration_id', $integration->id)->count();
                
                $plugin->fetchData($integration);
                
                // Count events after processing
                $eventsAfter = \App\Models\Event::where('integration_id', $integration->id)->count();
                $newEvents = $eventsAfter - $eventsBefore;
                
                if ($newEvents > 0) {
                    $this->info("Created {$newEvents} new event(s)");
                    $eventsCreated += $newEvents;
                } else {
                    $this->info("No new events created");
                }
                
                // Mark as successfully updated after processing
                $integration->markAsSuccessfullyUpdated();
                
                $successCount++;
                
            } catch (\Exception $e) {
                $this->error("Failed to fetch Spotify data for integration {$integration->id}: " . $e->getMessage());
                $errorCount++;
            }
        }
        
        $this->info("Completed: {$successCount} successful, {$errorCount} failed, {$eventsCreated} total events created");
        
        return $errorCount === 0 ? 0 : 1;
    }
}
