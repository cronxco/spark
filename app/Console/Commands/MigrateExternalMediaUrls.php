<?php

namespace App\Console\Commands;

use App\Jobs\Media\MigrateExternalMediaUrlJob;
use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateExternalMediaUrls extends Command
{
    protected $signature = 'media:migrate-external-urls
                            {--model= : The model to migrate (EventObject, Block, or both)}
                            {--limit= : Limit the number of records to migrate}
                            {--user= : User ID to associate with progress tracking (required)}
                            {--dry-run : Show what would be migrated without actually migrating}';

    protected $description = 'Migrate external media URLs to Media Library with deduplication';

    protected ?int $userId = null;

    public function handle(): int
    {
        $this->info('🎨 Media URL Migration Tool');
        $this->newLine();

        $modelOption = $this->option('model');
        $limit = $this->option('limit');
        $dryRun = $this->option('dry-run');
        $userOption = $this->option('user');

        // Validate user for non-dry-run
        if (! $dryRun) {
            if (! $userOption) {
                // Try to get the first user if none specified
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
        }

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Determine which models to migrate
        $models = $this->getModelsToMigrate($modelOption);

        if (empty($models)) {
            $this->error('No valid models specified. Use --model=EventObject, --model=Block, or omit for both.');

            return self::FAILURE;
        }

        // Gather statistics
        $stats = $this->gatherStatistics($models);

        $this->displayStatistics($stats);

        if ($dryRun) {
            $this->info('✅ Dry run complete. Use without --dry-run to perform migration.');

            return self::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm('Do you want to proceed with the migration?')) {
            $this->info('Migration cancelled.');

            return self::SUCCESS;
        }

        // Start migration
        $this->info('🚀 Starting migration...');
        $this->newLine();

        $totalJobs = 0;

        foreach ($models as $modelClass) {
            $count = $this->migrateModel($modelClass, $limit);
            $totalJobs += $count;
        }

        $this->newLine();
        $this->info("✅ Migration started. Dispatched {$totalJobs} jobs to the migration queue.");
        $this->info('💡 Monitor progress with: php artisan queue:work --queue=migration');

        return self::SUCCESS;
    }

    protected function getModelsToMigrate(?string $modelOption): array
    {
        if (! $modelOption) {
            return [EventObject::class, Block::class];
        }

        return match ($modelOption) {
            'EventObject' => [EventObject::class],
            'Block' => [Block::class],
            'both' => [EventObject::class, Block::class],
            default => [],
        };
    }

    protected function gatherStatistics(array $models): array
    {
        $stats = [];

        foreach ($models as $modelClass) {
            $table = (new $modelClass)->getTable();
            $count = DB::table($table)
                ->whereNotNull('media_url')
                ->whereNull('deleted_at')
                ->count();

            $stats[$modelClass] = [
                'count' => $count,
                'table' => $table,
            ];
        }

        return $stats;
    }

    protected function displayStatistics(array $stats): void
    {
        $this->table(
            ['Model', 'Table', 'Records with media_url'],
            collect($stats)->map(function ($data, $model) {
                return [
                    class_basename($model),
                    $data['table'],
                    number_format($data['count']),
                ];
            })->values()
        );

        $this->newLine();
        $totalRecords = collect($stats)->sum('count');
        $this->info('📊 Total records to migrate: '.number_format($totalRecords));
        $this->newLine();
    }

    protected function migrateModel(string $modelClass, ?int $limit): int
    {
        $modelName = class_basename($modelClass);
        $this->info("Migrating {$modelName}...");

        // Create progress tracker
        $progress = ActionProgress::create([
            'user_id' => $this->userId,
            'action_type' => 'media_migration',
            'action_id' => "migrate_media_urls_{$modelName}_".now()->format('Y-m-d_H-i-s'),
            'step' => 'dispatching',
            'total' => 0, // Will be updated as we dispatch jobs
            'processed' => 0,
            'status' => 'in_progress',
        ]);

        // Get records with media_url
        $query = $modelClass::whereNotNull('media_url')
            ->whereNull('deleted_at')
            ->select('id', 'media_url');

        if ($limit) {
            $query->limit($limit);
        }

        $records = $query->get();

        $progress->update(['total' => $records->count()]);

        // Dispatch jobs
        $bar = $this->output->createProgressBar($records->count());
        $bar->start();

        $jobsDispatched = 0;

        foreach ($records as $record) {
            MigrateExternalMediaUrlJob::dispatch(
                $modelClass,
                $record->id,
                $record->media_url,
                $progress->id
            )->onQueue('migration');

            $jobsDispatched++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("  ✓ Dispatched {$jobsDispatched} jobs for {$modelName}");

        return $jobsDispatched;
    }
}
