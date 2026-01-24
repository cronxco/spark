<?php

namespace App\Console\Commands;

use App\Models\Block;
use Exception;
use Illuminate\Console\Command;

class BackfillAppleHealthBlockTypes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blocks:backfill-apple-health-types
                            {--batch-size=500 : Number of blocks to process per batch}
                            {--limit= : Optional limit on total blocks to update}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill block_type for Apple Health blocks that have empty block_type';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Backfilling Apple Health Block Types');
        $this->newLine();

        // Count blocks to update
        $totalBlocks = Block::whereHas('event', function ($query) {
            $query->where('service', 'apple_health');
        })
            ->where(function ($query) {
                $query->whereNull('block_type')
                    ->orWhere('block_type', '');
            })
            ->count();

        if ($totalBlocks === 0) {
            $this->info('No Apple Health blocks found with empty block_type.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalBlocks} Apple Health blocks with empty block_type.");
        $this->newLine();

        // Show configuration
        $batchSize = (int) $this->option('batch-size');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->table(
            ['Setting', 'Value'],
            [
                ['Batch Size', $batchSize],
                ['Limit', $limit ?? 'None'],
                ['Total Blocks', $limit ? min($totalBlocks, $limit) : $totalBlocks],
            ]
        );
        $this->newLine();

        // Confirm unless --force
        if (! $this->option('force')) {
            if (! $this->confirm('This will update the block_type field based on metadata. Continue?')) {
                $this->warn('Backfill cancelled.');

                return self::FAILURE;
            }
            $this->newLine();
        }

        // Process blocks
        $this->info('Processing blocks...');
        $this->newLine();

        try {
            $processedCount = 0;
            $updatedCount = 0;
            $errorCount = 0;

            $query = Block::whereHas('event', function ($query) {
                $query->where('service', 'apple_health');
            })
                ->where(function ($query) {
                    $query->whereNull('block_type')
                        ->orWhere('block_type', '');
                })
                ->with('event');

            if ($limit) {
                $query->limit($limit);
            }

            $bar = $this->output->createProgressBar($limit ?? $totalBlocks);
            $bar->start();

            $query->chunkById($batchSize, function ($blocks) use (&$processedCount, &$updatedCount, &$errorCount, $bar) {
                foreach ($blocks as $block) {
                    $processedCount++;

                    try {
                        // Extract metric name from metadata
                        $metricName = $block->metadata['metric'] ?? null;

                        if ($metricName) {
                            $block->update(['block_type' => $metricName]);
                            $updatedCount++;
                        } else {
                            // Try to infer from title
                            $title = strtolower($block->title ?? '');

                            // Map common titles to block types
                            $blockType = match ($title) {
                                'duration' => 'duration',
                                'active energy' => 'energy',
                                'intensity' => 'intensity',
                                'distance' => 'distance',
                                'minimum', 'average', 'maximum' => $block->metadata['metric'] ?? '',
                                default => ''
                            };

                            if ($blockType !== '') {
                                $block->update(['block_type' => $blockType]);
                                $updatedCount++;
                            } else {
                                $errorCount++;
                            }
                        }
                    } catch (Exception $e) {
                        $errorCount++;
                        $this->error("\nError processing block {$block->id}: ".$e->getMessage());
                    }

                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine(2);

            $this->info('✓ Backfill completed!');
            $this->newLine();

            // Show final stats
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Processed', $processedCount],
                    ['Successfully Updated', $updatedCount],
                    ['Errors/Skipped', $errorCount],
                ]
            );

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Backfill failed: '.$e->getMessage());
            $this->newLine();
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
