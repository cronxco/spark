<?php

namespace App\Console\Commands\TaskPipeline;

use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Console\Command;

class ListTasksCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task-pipeline:list
                            {--plugin= : Filter by plugin class name}
                            {--applies-to= : Filter by model type (event, block, object, integration)}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all registered tasks in the task pipeline';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tasks = collect(TaskRegistry::getAllTasks());

        // Apply filters
        if ($plugin = $this->option('plugin')) {
            $tasks = $tasks->filter(fn ($task) => $task->registeredBy === $plugin);
        }

        if ($appliesTo = $this->option('applies-to')) {
            $tasks = $tasks->filter(fn ($task) => in_array($appliesTo, $task->appliesTo));
        }

        if ($tasks->isEmpty()) {
            $this->warn('No tasks found matching the specified filters');

            return Command::SUCCESS;
        }

        if ($this->option('json')) {
            $this->outputJson($tasks);
        } else {
            $this->outputTable($tasks);
        }

        return Command::SUCCESS;
    }

    /**
     * Output tasks as a table
     */
    protected function outputTable($tasks): void
    {
        $this->info('Registered Tasks:');
        $this->newLine();

        $this->table(
            ['Key', 'Name', 'Applies To', 'Dependencies', 'Priority', 'Queue', 'Source'],
            $tasks->sortByDesc('priority')->map(fn ($task) => [
                $task->key,
                $task->name,
                implode(', ', $task->appliesTo),
                count($task->dependencies) . ' deps',
                $task->priority,
                $task->queue,
                $task->registeredBy ? 'Plugin' : 'Core',
            ])->values()->toArray()
        );

        $this->newLine();
        $this->info('Total: ' . $tasks->count() . ' tasks');
        $this->comment('Core: ' . $tasks->filter(fn ($t) => ! $t->registeredBy)->count());
        $this->comment('Plugin: ' . $tasks->filter(fn ($t) => $t->registeredBy)->count());
    }

    /**
     * Output tasks as JSON
     */
    protected function outputJson($tasks): void
    {
        $output = $tasks->map(fn ($task) => [
            'key' => $task->key,
            'name' => $task->name,
            'description' => $task->description,
            'job_class' => $task->jobClass,
            'applies_to' => $task->appliesTo,
            'conditions' => $task->conditions,
            'dependencies' => $task->dependencies,
            'queue' => $task->queue,
            'priority' => $task->priority,
            'run_on_create' => $task->runOnCreate,
            'run_on_update' => $task->runOnUpdate,
            'registered_by' => $task->registeredBy,
        ])->values()->toArray();

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }
}
