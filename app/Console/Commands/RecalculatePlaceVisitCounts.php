<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculatePlaceVisitCounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'places:recalculate-visit-counts
                            {--dry-run : Preview changes without saving}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate visit counts for all places based on actual event relationships';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Recalculating Place Visit Counts');
        $this->newLine();

        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be saved');
            $this->newLine();
        }

        // Step 1: Calculate all visit counts in a single efficient query
        $this->info('Calculating visit counts...');

        // Get table prefix to handle prefixed environments
        $prefix = DB::getTablePrefix();

        $calculatedData = DB::table('objects as o')
            ->select([
                'o.id',
                'o.title',
                DB::raw("CAST(o.metadata->>'visit_count' AS INTEGER) as current_count"),
                DB::raw("COUNT(DISTINCT {$prefix}r.from_id) as actual_count"),
                DB::raw("MIN({$prefix}e.time) as first_visit"),
                DB::raw("MAX({$prefix}e.time) as last_visit"),
            ])
            ->leftJoin('relationships as r', function ($join) use ($prefix) {
                $join->on("{$prefix}r.to_id", '=', 'o.id')
                    ->where("{$prefix}r.to_type", '=', 'App\Models\EventObject')
                    ->where("{$prefix}r.type", '=', 'occurred_at')
                    ->whereNull("{$prefix}r.deleted_at");
            })
            ->leftJoin('events as e', function ($join) use ($prefix) {
                $join->on("{$prefix}e.id", '=', "{$prefix}r.from_id")
                    ->whereNull("{$prefix}e.deleted_at");
            })
            ->where('o.concept', '=', 'place')
            ->whereNull('o.deleted_at')
            ->groupBy('o.id', 'o.title', 'o.metadata')
            ->get();

        $totalPlaces = $calculatedData->count();

        if ($totalPlaces === 0) {
            $this->info('No places found.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalPlaces} places.");
        $this->newLine();

        // Filter to only places that need updating
        $placesToUpdate = $calculatedData->filter(function ($place) {
            return ($place->current_count ?? 0) !== $place->actual_count;
        });

        $updateCount = $placesToUpdate->count();

        $this->info("Places needing updates: {$updateCount}");
        $this->info('Places already correct: ' . ($totalPlaces - $updateCount));
        $this->newLine();

        if ($updateCount === 0) {
            $this->info('All place visit counts are already correct!');

            return self::SUCCESS;
        }

        // Confirm unless --force or --dry-run
        if (! $isDryRun && ! $this->option('force')) {
            if (! $this->confirm("This will update {$updateCount} places. Continue?")) {
                $this->warn('Operation cancelled.');

                return self::FAILURE;
            }
            $this->newLine();
        }

        // Step 2: Bulk update in chunks
        $stats = [
            'total' => $totalPlaces,
            'updated' => 0,
            'unchanged' => $totalPlaces - $updateCount,
            'errors' => 0,
        ];

        $changes = [];

        if (! $isDryRun) {
            $this->info('Updating places...');
            $progressBar = $this->output->createProgressBar($updateCount);
            $progressBar->start();

            // Process in chunks of 500 for efficient bulk updates
            $placesToUpdate->chunk(500)->each(function ($chunk) use (&$stats, &$progressBar) {
                DB::transaction(function () use ($chunk, &$stats, &$progressBar) {
                    foreach ($chunk as $place) {
                        try {
                            // Build updated metadata
                            $currentMetadata = DB::table('objects')
                                ->where('id', $place->id)
                                ->value('metadata');

                            $metadata = $currentMetadata ? json_decode($currentMetadata, true) : [];
                            $metadata['visit_count'] = $place->actual_count;

                            if ($place->first_visit) {
                                $metadata['first_visit_at'] = $place->first_visit;
                            }

                            if ($place->last_visit) {
                                $metadata['last_visit_at'] = $place->last_visit;
                            }

                            // Update using raw query for efficiency
                            DB::table('objects')
                                ->where('id', $place->id)
                                ->update([
                                    'metadata' => json_encode($metadata),
                                    'updated_at' => now(),
                                ]);

                            $stats['updated']++;
                            $progressBar->advance();
                        } catch (Exception $e) {
                            $stats['errors']++;
                            // Continue processing other places
                        }
                    }
                });
            });

            $progressBar->finish();
            $this->newLine(2);
        } else {
            $stats['updated'] = $updateCount;
        }

        // Track changes for display
        foreach ($placesToUpdate->take(20) as $place) {
            $changes[] = [
                'id' => $place->id,
                'title' => $place->title,
                'old_count' => $place->current_count ?? 0,
                'new_count' => $place->actual_count,
            ];
        }

        // Show results
        if ($isDryRun) {
            $this->info('DRY RUN RESULTS:');
        } else {
            $this->info('✓ Recalculation completed!');
        }
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Places', $stats['total']],
                ['Updated', $stats['updated']],
                ['Unchanged', $stats['unchanged']],
                ['Errors', $stats['errors']],
            ]
        );

        // Show sample of changes if any
        if (! empty($changes)) {
            $this->newLine();
            $this->info('Sample of changes (first 20):');
            $this->newLine();

            $sampleChanges = array_slice($changes, 0, 20);
            $this->table(
                ['Place ID', 'Title', 'Old Count', 'New Count', 'Difference'],
                array_map(function ($change) {
                    return [
                        substr($change['id'], 0, 8) . '...',
                        substr($change['title'], 0, 40),
                        $change['old_count'],
                        $change['new_count'],
                        ($change['new_count'] - $change['old_count']) > 0 ? '+' . ($change['new_count'] - $change['old_count']) : ($change['new_count'] - $change['old_count']),
                    ];
                }, $sampleChanges)
            );

            if (count($changes) > 20) {
                $this->info('... and ' . (count($changes) - 20) . ' more changes.');
            }
        }

        if ($isDryRun) {
            $this->newLine();
            $this->info('Run without --dry-run to apply these changes.');
        }

        return self::SUCCESS;
    }
}
