<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBlockEmbeddingJob;
use App\Jobs\GenerateEventEmbeddingJob;
use App\Jobs\GenerateObjectEmbeddingJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Console\Command;

class GenerateEmbeddings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'embeddings:generate
                            {--type=all : Type of records to process (all, events, blocks, objects)}
                            {--force : Force regenerate embeddings even if they already exist}
                            {--batch=100 : Number of records to process in each batch}
                            {--limit= : Maximum number of records to process}
                            {--user= : Only process records belonging to this user id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate embeddings for events, blocks, and objects';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $force = $this->option('force');
        $batchSize = (int) $this->option('batch');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $userId = $this->option('user') ?: null;

        $this->info('Starting embedding generation...');
        $this->info('Type: ' . $type);
        $this->info('Force: ' . ($force ? 'Yes' : 'No'));
        $this->info('Batch size: ' . $batchSize);

        if ($type === 'all' || $type === 'events') {
            $this->generateEventEmbeddings($force, $batchSize, $limit, $userId);
        }

        if ($type === 'all' || $type === 'blocks') {
            $this->generateBlockEmbeddings($force, $batchSize, $limit, $userId);
        }

        if ($type === 'all' || $type === 'objects') {
            $this->generateObjectEmbeddings($force, $batchSize, $limit, $userId);
        }

        $this->info('Embedding generation completed!');

        return Command::SUCCESS;
    }

    /**
     * Generate embeddings for events
     */
    private function generateEventEmbeddings(bool $force, int $batchSize, ?int $limit, ?string $userId = null): void
    {
        $this->info('Processing events...');

        $query = Event::query()->withoutInternal();

        if ($userId) {
            $query->forUser($userId);
        }

        if (! $force) {
            $query->whereNull('embeddings');
        }

        $total = $limit ? min($limit, $query->count()) : $query->count();
        $this->info("Found {$total} events to process");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunk($batchSize, function ($events) use (&$processed, $bar, $limit) {
            foreach ($events as $event) {
                if ($limit && $processed >= $limit) {
                    return false;
                }

                GenerateEventEmbeddingJob::dispatch($event)->onQueue('embeddings');
                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$processed} event embedding jobs to queue");
    }

    /**
     * Generate embeddings for blocks
     */
    private function generateBlockEmbeddings(bool $force, int $batchSize, ?int $limit, ?string $userId = null): void
    {
        $this->info('Processing blocks...');

        $query = Block::query();

        if ($userId) {
            $query->whereHas('event.integration', fn ($q) => $q->where('user_id', $userId));
        }

        if (! $force) {
            $query->whereNull('embeddings');
        }

        $total = $limit ? min($limit, $query->count()) : $query->count();
        $this->info("Found {$total} blocks to process");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunk($batchSize, function ($blocks) use (&$processed, $bar, $limit) {
            foreach ($blocks as $block) {
                if ($limit && $processed >= $limit) {
                    return false;
                }

                GenerateBlockEmbeddingJob::dispatch($block)->onQueue('embeddings');
                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$processed} block embedding jobs to queue");
    }

    /**
     * Generate embeddings for objects
     */
    private function generateObjectEmbeddings(bool $force, int $batchSize, ?int $limit, ?string $userId = null): void
    {
        $this->info('Processing objects...');

        $query = EventObject::query();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if (! $force) {
            $query->whereNull('embeddings');
        }

        $total = $limit ? min($limit, $query->count()) : $query->count();
        $this->info("Found {$total} objects to process");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunk($batchSize, function ($objects) use (&$processed, $bar, $limit) {
            foreach ($objects as $object) {
                if ($limit && $processed >= $limit) {
                    return false;
                }

                GenerateObjectEmbeddingJob::dispatch($object)->onQueue('embeddings');
                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$processed} object embedding jobs to queue");
    }
}
