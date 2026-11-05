<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Services\TaskPipeline\Exceptions\CircularDependencyException;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskRegistryFilteredDepsTest extends TestCase
{
    protected function setUp(): void
    {
        TaskRegistry::clear(); // clear before boot to avoid validateDependencies() seeing stale tasks
        parent::setUp();
        TaskRegistry::clear();
    }

    protected function tearDown(): void
    {
        TaskRegistry::clear();
        parent::tearDown();
    }

    #[Test]
    public function resolves_order_when_dependency_is_excluded_from_filter(): void
    {
        // detect_anomalies depends on calculate_metric_stats, but only detect_anomalies is in the batch
        $detectAnomalies = new TaskDefinition(
            key: 'detect_anomalies',
            name: 'Detect Anomalies',
            description: 'Detects anomalies',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['calculate_metric_stats'],
        );

        $tasks = collect([$detectAnomalies]);

        // Should not throw — external dependency treated as already satisfied
        $ordered = TaskRegistry::resolveExecutionOrder($tasks);

        $this->assertCount(1, $ordered);
        $this->assertEquals('detect_anomalies', $ordered->first()->key);
    }

    #[Test]
    public function still_detects_circular_dependency_within_batch(): void
    {
        $taskA = new TaskDefinition(
            key: 'task_a',
            name: 'Task A',
            description: 'Depends on task_b',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['task_b'],
        );

        $taskB = new TaskDefinition(
            key: 'task_b',
            name: 'Task B',
            description: 'Depends on task_a',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['task_a'],
        );

        $tasks = collect([$taskA, $taskB]);

        $this->expectException(CircularDependencyException::class);
        TaskRegistry::resolveExecutionOrder($tasks);
    }

    #[Test]
    public function resolves_correct_order_when_dependency_is_in_batch(): void
    {
        $stats = new TaskDefinition(
            key: 'calculate_metric_stats',
            name: 'Calculate Stats',
            description: 'Calculates metric stats',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: [],
        );

        $anomalies = new TaskDefinition(
            key: 'detect_anomalies',
            name: 'Detect Anomalies',
            description: 'Detects anomalies',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['calculate_metric_stats'],
        );

        $tasks = collect([$anomalies, $stats]); // Wrong order intentionally

        $ordered = TaskRegistry::resolveExecutionOrder($tasks);

        $keys = $ordered->pluck('key')->toArray();
        $this->assertEquals(['calculate_metric_stats', 'detect_anomalies'], $keys);
    }
}
