<?php

namespace App\Console\Commands;

use App\Jobs\Migrations\MigrateHadLinkToRelationships as MigrateHadLinkToRelationshipsJob;
use App\Models\Event;
use Exception;
use Illuminate\Console\Command;

class MigrateHadLinkToRelationships extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'relationships:migrate-had-link-to
                            {--batch-size=100 : Number of events to process per batch}
                            {--limit= : Optional limit on total events to migrate}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate had_link_to events to the new Relationship model';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Relationship Migration: had_link_to events → Relationship model');
        $this->newLine();

        // Count events to migrate
        $totalEvents = Event::where('action', 'had_link_to')
            ->whereNull('deleted_at')
            ->count();

        if ($totalEvents === 0) {
            $this->info('No had_link_to events found to migrate.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalEvents} had_link_to events to migrate.");
        $this->newLine();

        // Show configuration
        $batchSize = (int) $this->option('batch-size');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->table(
            ['Setting', 'Value'],
            [
                ['Batch Size', $batchSize],
                ['Limit', $limit ?? 'None'],
                ['Total Events', $limit ? min($totalEvents, $limit) : $totalEvents],
            ]
        );
        $this->newLine();

        // Confirm unless --force
        if (! $this->option('force')) {
            if (! $this->confirm('This will soft-delete the original events after creating relationships. Continue?')) {
                $this->warn('Migration cancelled.');

                return self::FAILURE;
            }
            $this->newLine();
        }

        // Dispatch the job
        $this->info('Dispatching migration job...');
        $this->newLine();

        $job = new MigrateHadLinkToRelationshipsJob($batchSize, $limit);

        // Run synchronously for progress feedback
        try {
            $job->handle();

            $this->newLine();
            $this->info('✓ Migration completed successfully!');
            $this->newLine();

            // Show final stats
            $remainingEvents = Event::where('action', 'had_link_to')
                ->whereNull('deleted_at')
                ->count();

            $migratedCount = $totalEvents - $remainingEvents;

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Original Events', $totalEvents],
                    ['Migrated', $migratedCount],
                    ['Remaining', $remainingEvents],
                ]
            );

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Migration failed: ' . $e->getMessage());
            $this->newLine();
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
