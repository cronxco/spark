<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Place;
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

        // Get all places
        $places = Place::withoutGlobalScopes()->get();
        $totalPlaces = $places->count();

        if ($totalPlaces === 0) {
            $this->info('No places found.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalPlaces} places to recalculate.");
        $this->newLine();

        // Confirm unless --force or --dry-run
        if (! $isDryRun && ! $this->option('force')) {
            if (! $this->confirm('This will update visit counts for all places. Continue?')) {
                $this->warn('Operation cancelled.');

                return self::FAILURE;
            }
            $this->newLine();
        }

        // Process places
        $progressBar = $this->output->createProgressBar($totalPlaces);
        $progressBar->start();

        $stats = [
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ];

        $changes = [];

        foreach ($places as $place) {
            $stats['total']++;

            try {
                // Count unique events linked to this place via "occurred_at" relationships
                $actualVisitCount = DB::table('relationships')
                    ->where('to_type', 'App\Models\EventObject')
                    ->where('to_id', $place->id)
                    ->where('type', 'occurred_at')
                    ->whereNull('deleted_at')
                    ->distinct('from_id')
                    ->count('from_id');

                $currentVisitCount = $place->visit_count ?? 0;

                if ($actualVisitCount !== $currentVisitCount) {
                    $changes[] = [
                        'id' => $place->id,
                        'title' => $place->title,
                        'old_count' => $currentVisitCount,
                        'new_count' => $actualVisitCount,
                    ];

                    if (! $isDryRun) {
                        // Get first and last visit times from related events
                        $eventTimes = DB::table('events')
                            ->join('relationships', function ($join) use ($place) {
                                $join->on('events.id', '=', 'relationships.from_id')
                                    ->where('relationships.from_type', '=', 'App\Models\Event')
                                    ->where('relationships.to_type', '=', 'App\Models\EventObject')
                                    ->where('relationships.to_id', '=', $place->id)
                                    ->where('relationships.type', '=', 'occurred_at')
                                    ->whereNull('relationships.deleted_at');
                            })
                            ->whereNull('events.deleted_at')
                            ->selectRaw('MIN(events.time) as first_visit, MAX(events.time) as last_visit')
                            ->first();

                        // Update place metadata
                        $metadata = $place->metadata ?? [];
                        $metadata['visit_count'] = $actualVisitCount;

                        if ($eventTimes && $eventTimes->first_visit) {
                            $metadata['first_visit_at'] = $eventTimes->first_visit;
                        }

                        if ($eventTimes && $eventTimes->last_visit) {
                            $metadata['last_visit_at'] = $eventTimes->last_visit;
                        }

                        $place->metadata = $metadata;
                        $place->save();
                    }

                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $this->newLine();
                $this->error("Error processing place {$place->id}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

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
