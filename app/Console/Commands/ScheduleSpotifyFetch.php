<?php

namespace App\Console\Commands;

use App\Jobs\ProcessSpotifyData;
use App\Models\Integration;
use Exception;
use Illuminate\Console\Command;

class ScheduleSpotifyFetch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spotify:schedule {--user= : Schedule for a specific user ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Schedule Spotify data fetching jobs for all active integrations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->option('user');

        $query = Integration::where('service', 'spotify')
            ->whereHas('user')
            ->whereNotNull('access_token'); // Only active integrations

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('No active Spotify integrations found.');

            return 0;
        }

        $this->info("Scheduling Spotify data fetching for {$integrations->count()} integration(s).");

        $scheduledCount = 0;

        foreach ($integrations as $integration) {
            try {
                // Check if it's time to fetch data (every 30 seconds for Spotify)
                if ($integration->last_triggered_at &&
                    $integration->last_triggered_at->addSeconds(30)->isFuture()) {
                    $this->line("Skipping integration {$integration->id} - too soon since last update");

                    continue;
                }

                // Dispatch the job
                ProcessSpotifyData::dispatch($integration);

                $this->line("Scheduled job for user: {$integration->user->name} (ID: {$integration->user_id})");
                $scheduledCount++;

            } catch (Exception $e) {
                $this->error("Failed to schedule job for integration {$integration->id}: " . $e->getMessage());
            }
        }

        $this->info("Successfully scheduled {$scheduledCount} Spotify data fetching job(s).");

        return 0;
    }
}
