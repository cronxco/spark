<?php

namespace App\Console\Commands\TaskPipeline;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Console\Command;

class RerunTaskCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task-pipeline:rerun
                            {task : Task key to re-run}
                            {model : Model type (event, block, object, integration)}
                            {id : Model ID}
                            {--force : Force re-run even if already successful}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-run a specific task for a specific item';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $modelClass = $this->getModelClass($this->argument('model'));
        $taskKey = $this->argument('task');
        $id = $this->argument('id');

        if (! $modelClass) {
            $this->error('Invalid model type. Must be one of: event, block, object, integration');

            return Command::FAILURE;
        }

        $model = $modelClass::find($id);

        if (! $model) {
            $this->error("{$this->argument('model')} with ID {$id} not found");

            return Command::FAILURE;
        }

        ProcessTaskPipelineJob::dispatch(
            model: $model,
            trigger: 'manual',
            taskFilter: [$taskKey],
            force: $this->option('force'),
        )->onQueue('tasks');

        $this->info("Task '{$taskKey}' queued for re-run on {$this->argument('model')} #{$id}");

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
