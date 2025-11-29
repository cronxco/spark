<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TaskRegistry::clear();
    }

    public function test_list_tasks_command_displays_registered_tasks(): void
    {
        TaskRegistry::register(new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            priority: 50,
        ));

        $this->artisan('task-pipeline:list')
            ->expectsOutputToContain('Test Task')
            ->assertExitCode(0);
    }

    public function test_list_tasks_command_with_json_output(): void
    {
        TaskRegistry::register(new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
        ));

        $this->artisan('task-pipeline:list --json')
            ->expectsOutputToContain('"key": "test_task"')
            ->assertExitCode(0);
    }

    public function test_list_tasks_command_filters_by_model_type(): void
    {
        TaskRegistry::register(new TaskDefinition(
            key: 'event_task',
            name: 'Event Task',
            description: 'For events',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
        ));

        TaskRegistry::register(new TaskDefinition(
            key: 'block_task',
            name: 'Block Task',
            description: 'For blocks',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['block'],
        ));

        $this->artisan('task-pipeline:list --applies-to=event')
            ->expectsOutputToContain('Event Task')
            ->doesntExpectOutputToContain('Block Task')
            ->assertExitCode(0);
    }

    public function test_rerun_command_validates_model_type(): void
    {
        $this->artisan('task-pipeline:rerun test_task invalid_model abc-123')
            ->expectsOutputToContain('Invalid model type')
            ->assertExitCode(1);
    }

    public function test_bulk_rerun_command_validates_model_type(): void
    {
        $this->artisan('task-pipeline:bulk-rerun test_task invalid_model')
            ->expectsOutputToContain('Invalid model type')
            ->assertExitCode(1);
    }

    public function test_bulk_rerun_command_dry_run_mode(): void
    {
        $this->artisan('task-pipeline:bulk-rerun test_task event --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);
    }
}
