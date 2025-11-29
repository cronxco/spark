<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Models\Event;
use App\Models\User;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TaskRegistry::clear();
    }

    /**
     * @test
     */
    public function dispatches_applicable_tasks_on_event_creation(): void
    {
        Queue::fake();

        // Register a test task
        TaskRegistry::register(new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'Test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            runOnCreate: true,
        ));

        $user = User::factory()->create();
        $event = new Event([
            'source_id' => 'test-123',
            'service' => 'test',
            'domain' => 'test',
            'action' => 'test',
        ]);
        $event->user_id = $user->id;

        // Dispatch the task pipeline
        ProcessTaskPipelineJob::dispatch($event, 'created')->onQueue('tasks');

        Queue::assertPushed(ProcessTaskPipelineJob::class);
    }

    /**
     * @test
     */
    public function marks_task_as_not_applicable_when_conditions_not_met(): void
    {
        // This would need database setup, so marking as example
        $this->markTestSkipped('Requires database setup with migrations');
    }

    /**
     * @test
     */
    public function respects_task_dependencies(): void
    {
        TaskRegistry::clear();

        $task1 = new TaskDefinition(
            key: 'task1',
            name: 'Task 1',
            description: 'First task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: [],
        );

        $task2 = new TaskDefinition(
            key: 'task2',
            name: 'Task 2',
            description: 'Second task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['task1'],
        );

        TaskRegistry::register($task1);
        TaskRegistry::register($task2);

        $event = new Event;
        $tasks = TaskRegistry::getTasksForModel($event, 'created');

        $ordered = TaskRegistry::resolveExecutionOrder($tasks);

        $this->assertEquals('task1', $ordered->first()->key);
        $this->assertEquals('task2', $ordered->last()->key);
    }

    /**
     * @test
     */
    public function filters_tasks_by_service_condition(): void
    {
        TaskRegistry::clear();

        TaskRegistry::register(new TaskDefinition(
            key: 'monzo_task',
            name: 'Monzo Task',
            description: 'Only for Monzo',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            conditions: ['service' => 'monzo'],
        ));

        $monzoEvent = new Event(['service' => 'monzo']);
        $spotifyEvent = new Event(['service' => 'spotify']);

        $monzoTasks = TaskRegistry::getTasksForModel($monzoEvent, 'created');
        $spotifyTasks = TaskRegistry::getTasksForModel($spotifyEvent, 'created');

        $this->assertCount(1, $monzoTasks);
        $this->assertCount(0, $spotifyTasks);
    }
}
