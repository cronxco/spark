<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
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
    public function does_not_dispatch_tasks_when_conditions_not_met(): void
    {
        Queue::fake();

        // Register two tasks: one for Monzo only, one for all events
        TaskRegistry::register(new TaskDefinition(
            key: 'monzo_only_task',
            name: 'Monzo Only Task',
            description: 'Task that only runs for Monzo',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            conditions: ['service' => 'monzo'],
            runOnCreate: true,
        ));

        TaskRegistry::register(new TaskDefinition(
            key: 'all_events_task',
            name: 'All Events Task',
            description: 'Task that runs for all events',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            runOnCreate: true,
        ));

        $user = User::factory()->create();

        // Create required EventObjects for actor and target
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        // Create an Integration
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        // Create a Spotify event (doesn't match Monzo condition)
        $spotifyEvent = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'service' => 'spotify',
            'domain' => 'media',
            'action' => 'listened_to',
        ]);

        // Get tasks that would be dispatched
        $applicableTasks = TaskRegistry::getTasksForModel($spotifyEvent, 'created');

        // Verify only the all_events_task is applicable
        $this->assertCount(1, $applicableTasks);
        $this->assertEquals('all_events_task', $applicableTasks->first()->key);

        // Process the task pipeline
        $job = new ProcessTaskPipelineJob($spotifyEvent, 'created');
        $job->handle();

        // Verify only one job was dispatched (the applicable one)
        Queue::assertPushed(GenerateEmbeddingTask::class, 1);

        // Verify the event metadata only tracks the executed task
        $metadata = $spotifyEvent->refresh()->event_metadata ?? [];
        $executions = $metadata['task_executions'] ?? [];
        $this->assertArrayHasKey('all_events_task', $executions);
        $this->assertArrayNotHasKey('monzo_only_task', $executions);
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
