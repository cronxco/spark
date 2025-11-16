<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBlockEmbeddingJob;
use App\Jobs\GenerateEventEmbeddingJob;
use App\Jobs\GenerateObjectEmbeddingJob;
use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Console\Command;

class RegenerateEmbeddings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'embeddings:regenerate
                            {--model= : Model to regenerate (Event, Block, EventObject). If not specified, regenerates all}
                            {--force : Regenerate all embeddings, even if they already exist}
                            {--filter= : Filter by service or domain (e.g., service:fetch or domain:health)}
                            {--sync : Run synchronously instead of queueing jobs}
                            {--limit= : Limit the number of records to process (for testing)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Regenerate embeddings for events, blocks, and objects';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelOption = $this->option('model');
        $force = $this->option('force');
        $filter = $this->option('filter');
        $sync = $this->option('sync');
        $limit = $this->option('limit');

        // Parse filter
        $filterType = null;
        $filterValue = null;
        if ($filter) {
            $parts = explode(':', $filter);
            if (count($parts) !== 2 || ! in_array($parts[0], ['service', 'domain'])) {
                $this->error('Invalid filter format. Use service:value or domain:value');

                return Command::FAILURE;
            }
            $filterType = $parts[0];
            $filterValue = $parts[1];
        }

        // Determine which models to process
        $models = [];
        if ($modelOption) {
            if (! in_array($modelOption, ['Event', 'Block', 'EventObject'])) {
                $this->error('Invalid model. Choose Event, Block, or EventObject');

                return Command::FAILURE;
            }
            $models = [$modelOption];
        } else {
            $models = ['Event', 'Block', 'EventObject'];
        }

        $this->info('🔄 Regenerating embeddings...');
        $this->newLine();

        $totalProcessed = 0;

        foreach ($models as $modelName) {
            $count = $this->processModel($modelName, $force, $filterType, $filterValue, $sync, $limit);
            $totalProcessed += $count;
        }

        $this->newLine();
        $this->info("✅ Processed {$totalProcessed} records total");
        $this->info('Jobs queued successfully. Check Horizon to monitor progress.');

        return Command::SUCCESS;
    }

    private function processModel(string $modelName, bool $force, ?string $filterType, ?string $filterValue, bool $sync, ?int $limit): int
    {
        $modelClass = match ($modelName) {
            'Event' => Event::class,
            'Block' => Block::class,
            'EventObject' => EventObject::class,
        };

        $jobClass = match ($modelName) {
            'Event' => GenerateEventEmbeddingJob::class,
            'Block' => GenerateBlockEmbeddingJob::class,
            'EventObject' => GenerateObjectEmbeddingJob::class,
        };

        $this->info("Processing {$modelName} records...");

        // Build query
        $query = $modelClass::query();

        // Apply filter
        if ($filterType === 'service') {
            if ($modelName === 'EventObject') {
                $this->warn('Service filter not applicable to EventObject, skipping');

                return 0;
            }

            if ($modelName === 'Event') {
                $query->where('service', $filterValue);
            } elseif ($modelName === 'Block') {
                $query->whereHas('event', function ($q) use ($filterValue) {
                    $q->where('service', $filterValue);
                });
            }
        } elseif ($filterType === 'domain') {
            if ($modelName === 'EventObject') {
                $this->warn('Domain filter not applicable to EventObject, skipping');

                return 0;
            }

            if ($modelName === 'Event') {
                $query->where('domain', $filterValue);
            } elseif ($modelName === 'Block') {
                $query->whereHas('event', function ($q) use ($filterValue) {
                    $q->where('domain', $filterValue);
                });
            }
        }

        // Apply force filter (regenerate all or only null embeddings)
        if (! $force) {
            $query->whereNull('embeddings');
        }

        // Apply limit
        if ($limit) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn("No {$modelName} records to process");

            return 0;
        }

        $this->line("Found {$total} {$modelName} records to process");

        // Create progress tracker
        $progressId = 'regenerate-embeddings-' . strtolower($modelName) . '-' . now()->timestamp;
        $progress = ActionProgress::create([
            'user_id' => 1, // System user
            'action_id' => $progressId,
            'total_items' => $total,
            'processed_items' => 0,
            'status' => 'in_progress',
            'metadata' => [
                'model' => $modelName,
                'force' => $force,
                'filter_type' => $filterType,
                'filter_value' => $filterValue,
            ],
        ]);

        // Process in chunks
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $query->chunkById(100, function ($records) use ($jobClass, $sync, $bar, &$processed, $progress) {
            foreach ($records as $record) {
                if ($sync) {
                    // Run synchronously
                    $job = new $jobClass($record);
                    $job->handle(app(\App\Services\EmbeddingService::class));
                } else {
                    // Queue job
                    $jobClass::dispatch($record);
                }

                $processed++;
                $bar->advance();

                // Update progress every 10 records
                if ($processed % 10 === 0) {
                    $progress->update(['processed_items' => $processed]);
                }
            }
        });

        $bar->finish();
        $this->newLine();

        // Mark progress as complete
        $progress->update([
            'processed_items' => $total,
            'status' => 'completed',
        ]);

        $this->info("✅ Queued {$processed} {$modelName} embedding jobs");

        return $processed;
    }
}
