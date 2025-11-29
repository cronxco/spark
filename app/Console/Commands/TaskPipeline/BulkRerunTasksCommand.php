<?php

namespace App\Console\Commands\TaskPipeline;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

class BulkRerunTasksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task-pipeline:bulk-rerun
                            {task : Task key to re-run}
                            {model : Model type (event, block, object, integration)}
                            {--service= : Filter by service}
                            {--domain= : Filter by domain}
                            {--action= : Filter by action}
                            {--since= : Only items created since date (e.g., 2025-01-01)}
                            {--limit= : Limit number of items}
                            {--force : Force re-run even if already successful}
                            {--dry-run : Preview without dispatching jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bulk re-run a task for filtered items';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelClass = $this->getModelClass($this->argument('model'));
        $taskKey = $this->argument('task');

        if (! $modelClass) {
            $this->error('Invalid model type. Must be one of: event, block, object, integration');

            return Command::FAILURE;
        }

        $query = $modelClass::query();

        // Apply filters
        if ($service = $this->option('service')) {
            $query->where('service', $service);
        }

        if ($domain = $this->option('domain')) {
            $query->where('domain', $domain);
        }

        if ($action = $this->option('action')) {
            $query->where('action', $action);
        }

        if ($since = $this->option('since')) {
            try {
                $query->where('created_at', '>=', Carbon::parse($since));
            } catch (Exception $e) {
                $this->error('Invalid date format for --since option');

                return Command::FAILURE;
            }
        }

        if ($limit = $this->option('limit')) {
            $query->limit($limit);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->warn('No items match the specified filters');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} items matching filters");

        if ($this->option('dry-run')) {
            $this->comment("DRY RUN: Would re-run task '{$taskKey}' for {$count} items");

            return Command::SUCCESS;
        }

        if (! $this->confirm("Re-run task '{$taskKey}' for {$count} items?", true)) {
            $this->comment('Operation cancelled');

            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $dispatched = 0;

        $query->chunk(100, function ($items) use ($bar, $taskKey, &$dispatched) {
            foreach ($items as $item) {
                ProcessTaskPipelineJob::dispatch(
                    model: $item,
                    trigger: 'manual',
                    taskFilter: [$taskKey],
                    force: $this->option('force'),
                )->onQueue('tasks');

                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$dispatched} tasks to queue");

        return Command::SUCCESS;
    }

    /**
     * Get model class from type string
     */
    protected function getModelClass(string $type): ?string
    {
        return match ($type) {
            'event' => Event::class,
            'block' => Block::class,
            'object' => EventObject::class,
            'integration' => Integration::class,
            default => null,
        };
    }
}
