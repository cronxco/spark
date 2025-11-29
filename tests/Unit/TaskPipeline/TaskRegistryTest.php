<?php

namespace Tests\Unit\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Models\Event;
use App\Services\TaskPipeline\Exceptions\CircularDependencyException;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use Tests\TestCase;

class TaskRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TaskRegistry::clear();
    }

    public function test_can_register_task(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
        );

        TaskRegistry::register($task);

        $this->assertNotNull(TaskRegistry::getTask('test_task'));
        $this->assertEquals('test_task', TaskRegistry::getTask('test_task')->key);
    }

    public function test_can_get_all_tasks(): void
    {
        $task1 = new TaskDefinition(
            key: 'task1',
            name: 'Task 1',
            description: 'First task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
        );

        $task2 = new TaskDefinition(
            key: 'task2',
            name: 'Task 2',
            description: 'Second task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['block'],
        );

        TaskRegistry::register($task1);
        TaskRegistry::register($task2);

        $all = TaskRegistry::getAllTasks();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('task1', $all);
        $this->assertArrayHasKey('task2', $all);
    }

    public function test_can_get_tasks_for_model(): void
    {
        $task1 = new TaskDefinition(
            key: 'event_task',
            name: 'Event Task',
            description: 'For events',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            runOnCreate: true,
        );

        $task2 = new TaskDefinition(
            key: 'block_task',
            name: 'Block Task',
            description: 'For blocks',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['block'],
            runOnCreate: true,
        );

        TaskRegistry::register($task1);
        TaskRegistry::register($task2);

        $event = new Event();
        $tasks = TaskRegistry::getTasksForModel($event, 'created');

        $this->assertCount(1, $tasks);
        $this->assertEquals('event_task', $tasks->first()->key);
    }

    public function test_filters_tasks_by_trigger(): void
    {
        $task = new TaskDefinition(
            key: 'update_only',
            name: 'Update Only',
            description: 'Only on update',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            runOnCreate: false,
            runOnUpdate: true,
        );

        TaskRegistry::register($task);

        $event = new Event();

        $createTasks = TaskRegistry::getTasksForModel($event, 'created');
        $this->assertCount(0, $createTasks);

        $updateTasks = TaskRegistry::getTasksForModel($event, 'updated');
        $this->assertCount(1, $updateTasks);
    }

    public function test_resolves_execution_order_with_dependencies(): void
    {
        $task1 = new TaskDefinition(
            key: 'task1',
            name: 'Task 1',
            description: 'No dependencies',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: [],
            priority: 50,
        );

        $task2 = new TaskDefinition(
            key: 'task2',
            name: 'Task 2',
            description: 'Depends on task1',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['task1'],
            priority: 60,
        );

        $task3 = new TaskDefinition(
            key: 'task3',
            name: 'Task 3',
            description: 'Depends on task2',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['task2'],
            priority: 70,
        );

        $tasks = collect([$task3, $task1, $task2]); // Intentionally wrong order

        $ordered = TaskRegistry::resolveExecutionOrder($tasks);

        $keys = $ordered->pluck('key')->toArray();
        $this->assertEquals(['task1', 'task2', 'task3'], $keys);
    }

    public function test_detects_circular_dependencies(): void
    {
        $task1 = new TaskDefinition(
            key: 'task1',
            name: 'Task 1',
            description: 'Depends on task2',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['task2'],
        );

        $task2 = new TaskDefinition(
            key: 'task2',
            name: 'Task 2',
            description: 'Depends on task1',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['task1'],
        );

        $tasks = collect([$task1, $task2]);

        $this->expectException(CircularDependencyException::class);
        TaskRegistry::resolveExecutionOrder($tasks);
    }

    public function test_clears_registry(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
        );

        TaskRegistry::register($task);
        $this->assertCount(1, TaskRegistry::getAllTasks());

        TaskRegistry::clear();
        $this->assertCount(0, TaskRegistry::getAllTasks());
    }
}
