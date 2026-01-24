<?php

namespace App\Console\Commands;

use App\Jobs\Fetch\ExtractArticleImageJob;
use App\Models\ActionProgress;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Console\Command;

class ExtractArticleImages extends Command
{
    protected $signature = 'fetch:extract-article-images
                            {--user= : User ID to migrate bookmarks for (required)}
                            {--limit= : Limit the number of bookmarks to process}
                            {--skip-existing : Skip bookmarks that already have an article image}
                            {--fetch-mode= : Filter by fetch mode (once, recurring, or all)}
                            {--dry-run : Show what would be migrated without actually migrating}';

    protected $description = 'Extract and save article images for existing fetch bookmarks';

    protected ?int $userId = null;

    public function handle(): int
    {
        $this->info('Article Image Extraction Tool');
        $this->newLine();

        $userOption = $this->option('user');
        $limit = $this->option('limit');
        $skipExisting = $this->option('skip-existing') ?? true;
        $fetchMode = $this->option('fetch-mode');
        $dryRun = $this->option('dry-run');

        // Validate user
        if (! $userOption) {
            $user = User::first();
            if (! $user) {
                $this->error('No users found. Please create a user first or specify --user=ID');

                return self::FAILURE;
            }
            $this->userId = $user->id;
            $this->warn("No --user specified, using first user: {$user->name} (ID: {$user->id})");
        } else {
            $user = User::find($userOption);
            if (! $user) {
                $this->error("User with ID {$userOption} not found.");

                return self::FAILURE;
            }
            $this->userId = $user->id;
            $this->info("Using user: {$user->name} (ID: {$user->id})");
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Build query for fetch bookmarks
        $query = EventObject::where('user_id', $this->userId)
            ->where('type', 'fetch_webpage')
            ->whereNotNull('url');

        // Filter by fetch mode if specified
        if ($fetchMode && $fetchMode !== 'all') {
            $query->whereJsonContains('metadata->fetch_mode', $fetchMode);
        }

        // Skip bookmarks that already have article images
        if ($skipExisting) {
            $query->whereDoesntHave('media', function ($q) {
                $q->where('collection_name', 'article_images');
            });
        }

        // Apply limit if specified
        if ($limit) {
            $query->limit((int) $limit);
        }

        // Get statistics
        $totalCount = (clone $query)->count();
        $this->info("Found {$totalCount} bookmarks to process");

        // Show breakdown by fetch mode
        $modeBreakdown = $this->getModeBreakdown($query);
        $this->table(
            ['Fetch Mode', 'Count'],
            collect($modeBreakdown)->map(fn ($count, $mode) => [$mode, $count])->values()
        );

        $this->newLine();

        if ($totalCount === 0) {
            $this->info('No bookmarks to process.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Dry run complete. Use without --dry-run to perform extraction.');

            return self::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm("Do you want to proceed with extracting article images for {$totalCount} bookmarks?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        // Create progress tracker
        $progress = ActionProgress::create([
            'user_id' => $this->userId,
            'action_type' => 'article_image_extraction',
            'action_id' => 'extract_article_images_'.now()->format('Y-m-d_H-i-s'),
            'step' => 'dispatching',
            'total' => $totalCount,
            'processed' => 0,
            'failed' => 0,
            'status' => 'in_progress',
        ]);

        $this->info('Starting article image extraction...');
        $this->newLine();

        // Dispatch jobs
        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $jobsDispatched = 0;

        $query->select('id')->chunk(100, function ($bookmarks) use (&$jobsDispatched, $progress, $bar) {
            foreach ($bookmarks as $bookmark) {
                ExtractArticleImageJob::dispatch(
                    $bookmark->id,
                    $progress->id
                )->onQueue('migration');

                $jobsDispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->newLine();

        $this->info("Dispatched {$jobsDispatched} jobs to the migration queue.");
        $this->info('Monitor progress with: php artisan queue:work --queue=migration');
        $this->newLine();

        $this->table(
            ['Progress ID', 'Total', 'Status'],
            [[$progress->id, $progress->total, $progress->status]]
        );

        return self::SUCCESS;
    }

    /**
     * Get breakdown of bookmarks by fetch mode.
     */
    protected function getModeBreakdown($query): array
    {
        $breakdown = [
            'once' => 0,
            'recurring' => 0,
            'unknown' => 0,
        ];

        // Clone the query and get all relevant bookmarks
        (clone $query)->select('id', 'metadata')->chunk(500, function ($bookmarks) use (&$breakdown) {
            foreach ($bookmarks as $bookmark) {
                $mode = $bookmark->metadata['fetch_mode'] ?? 'unknown';
                if (isset($breakdown[$mode])) {
                    $breakdown[$mode]++;
                } else {
                    $breakdown['unknown']++;
                }
            }
        });

        // Remove zero counts
        return array_filter($breakdown, fn ($count) => $count > 0);
    }
}
