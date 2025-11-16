<?php

namespace App\Console\Commands;

use App\Jobs\GenerateBlockEmbeddingJob;
use App\Jobs\GenerateEventEmbeddingJob;
use App\Jobs\GenerateObjectEmbeddingJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                            {--limit= : Maximum number of records to process}';

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

        $this->info('Starting embedding generation...');
        $this->info('Type: ' . $type);
        $this->info('Force: ' . ($force ? 'Yes' : 'No'));
        $this->info('Batch size: ' . $batchSize);

        if ($type === 'all' || $type === 'events') {
            $this->generateEventEmbeddings($force, $batchSize, $limit);
        }

        if ($type === 'all' || $type === 'blocks') {
            $this->generateBlockEmbeddings($force, $batchSize, $limit);
        }

        if ($type === 'all' || $type === 'objects') {
            $this->generateObjectEmbeddings($force, $batchSize, $limit);
        }

        $this->info('Embedding generation completed!');

        return Command::SUCCESS;
    }

    /**
     * Generate embeddings for events
     */
    private function generateEventEmbeddings(bool $force, int $batchSize, ?int $limit): void
    {
        $this->info('Processing events...');

        $query = Event::query();

        if (! $force) {
            $query->whereNull('embeddings');
        }

        if ($limit) {
            $query->limit($limit);
        }

        $total = $query->count();
        $this->info("Found {$total} events to process");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunk($batchSize, function ($events) use (&$processed, $bar) {
            foreach ($events as $event) {
                GenerateEventEmbeddingJob::dispatch($event);
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
    private function generateBlockEmbeddings(bool $force, int $batchSize, ?int $limit): void
    {
        $this->info('Processing blocks...');

        $query = Block::query();

        if (! $force) {
            $query->whereNull('embeddings');
        }

        if ($limit) {
            $query->limit($limit);
        }

        $total = $query->count();
        $this->info("Found {$total} blocks to process");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunk($batchSize, function ($blocks) use (&$processed, $bar) {
            foreach ($blocks as $block) {
                GenerateBlockEmbeddingJob::dispatch($block);
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
    private function generateObjectEmbeddings(bool $force, int $batchSize, ?int $limit): void
    {
        $this->info('Processing objects...');

        $query = EventObject::query();

        if (! $force) {
            $query->whereNull('embeddings');
        }

        if ($limit) {
            $query->limit($limit);
        }

        $total = $query->count();
        $this->info("Found {$total} objects to process");

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;

        $query->chunk($batchSize, function ($objects) use (&$processed, $bar) {
            foreach ($objects as $object) {
                GenerateObjectEmbeddingJob::dispatch($object);
                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$processed} object embedding jobs to queue");
    }
}
