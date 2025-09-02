<?php

namespace App\Console\Commands;

use App\Integrations\PluginRegistry;
use App\Jobs\CheckIntegrationUpdates;
use App\Models\Integration;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

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
    protected $description = 'Fetch data from integrations (OAuth/API key) that need updating based on their frequency settings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = $this->option('service');
        $force = $this->option('force');

        if ($service) {
            $this->info("Fetching data for {$service} integrations...");
            $services = PluginRegistry::getOAuthPlugins()->keys()->merge(PluginRegistry::getApiKeyPlugins()->keys());
            $query = Integration::where('service', $service)
                ->whereIn('service', $services);

            $allIntegrations = $query->get();

            if (! $force) {
                $integrations = $allIntegrations->filter(function ($integration) {
                    return $integration->needsUpdate();
                });
            } else {
                $integrations = $allIntegrations;
            }
        } else {
            $this->info('Fetching data from integrations that need updating...');
            $services = PluginRegistry::getOAuthPlugins()->keys()->merge(PluginRegistry::getApiKeyPlugins()->keys());
            $query = Integration::whereHas('user')
                ->whereIn('service', $services);

            $allIntegrations = $query->get();

            if (! $force) {
                $integrations = $allIntegrations->filter(function ($integration) {
                    return $integration->needsUpdate();
                });
            } else {
                $integrations = $allIntegrations;
            }
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
                // Skip if currently processing (includes migration)
                if ($integration->isProcessing()) {
                    $this->line("Skipping integration {$integration->id} ({$integration->service}) - currently processing");

                    continue;
                }

                // Guard: if a migration batch for this integration (or its group) is active, skip polling to avoid duplicates
                $activeMigration = false;
                if ($integration->migration_batch_id) {
                    $batch = Bus::findBatch($integration->migration_batch_id);
                    $activeMigration = $batch && ! $batch->finished();
                } elseif ($integration->integration_group_id) {
                    $activeMigration = Integration::where('integration_group_id', $integration->integration_group_id)
                        ->whereNotNull('migration_batch_id')
                        ->get()
                        ->contains(function ($i) {
                            $b = Bus::findBatch($i->migration_batch_id);

                            return $b && ! $b->finished();
                        });
                }
                if ($activeMigration) {
                    $this->line("Skipping integration {$integration->id} ({$integration->service}) - migration batch active");

                    continue;
                }

                // Check if it's time to fetch data based on update frequency
                if ($integration->last_triggered_at &&
                $integration->last_triggered_at->addMinutes($integration->getUpdateFrequencyMinutes())->isFuture()) {
                    $this->line("Skipping integration {$integration->id} ({$integration->service}) - too soon since last update");

                    continue;
                }

                // Mark this integration as needing update (don't dispatch yet)
                $this->line("Integration {$integration->id} ({$integration->service}) needs updating");
                $successCount++;

            } catch (Exception $e) {
                $this->error("Failed to schedule job for integration {$integration->id} ({$integration->service}): " . $e->getMessage());
                $errorCount++;
            }
        }

        // Dispatch the integration update check job if any integrations need updating
        if ($successCount > 0) {
            CheckIntegrationUpdates::dispatch();
            $this->info("Dispatched CheckIntegrationUpdates job to process {$successCount} integrations");
        }

        $this->info("Completed: {$successCount} successful, {$errorCount} failed");

        return $errorCount === 0 ? 0 : 1;
    }
}
