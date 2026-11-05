<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Services\TaskPipeline\Exceptions\UnresolvableDependencyException;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskRegistryValidateDepsTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear static registry before app boot; the service provider's validateDependencies()
        // runs during boot and would throw if stale tasks from a prior test are still present.
        TaskRegistry::clear();
        parent::setUp();
        TaskRegistry::clear(); // also clear the tasks registered by the service provider itself
    }

    protected function tearDown(): void
    {
        TaskRegistry::clear();
        parent::tearDown();
    }

    #[Test]
    public function passes_when_all_dependencies_are_registered(): void
    {
        TaskRegistry::register(new TaskDefinition(
            key: 'step_one',
            name: 'Step One',
            description: 'No deps',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: [],
        ));

        TaskRegistry::register(new TaskDefinition(
            key: 'step_two',
            name: 'Step Two',
            description: 'Depends on step_one',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['step_one'],
        ));

        // Should not throw
        TaskRegistry::validateDependencies();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function throws_when_a_dependency_is_not_registered(): void
    {
        TaskRegistry::register(new TaskDefinition(
            key: 'orphan_task',
            name: 'Orphan Task',
            description: 'Declares a dep that does not exist',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['missing_task'],
        ));

        $this->expectException(UnresolvableDependencyException::class);
        $this->expectExceptionMessage("'orphan_task' declares dependency 'missing_task'");

        TaskRegistry::validateDependencies();
    }

    #[Test]
    public function error_message_lists_all_broken_dependencies(): void
    {
        TaskRegistry::register(new TaskDefinition(
            key: 'task_a',
            name: 'Task A',
            description: 'Multiple missing deps',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            dependencies: ['missing_x', 'missing_y'],
        ));

        try {
            TaskRegistry::validateDependencies();
            $this->fail('Expected UnresolvableDependencyException was not thrown');
        } catch (UnresolvableDependencyException $e) {
            $this->assertStringContainsString('missing_x', $e->getMessage());
            $this->assertStringContainsString('missing_y', $e->getMessage());
        }
    }

    #[Test]
    public function passes_for_empty_registry(): void
    {
        // No tasks registered — nothing to validate
        TaskRegistry::validateDependencies();

        $this->addToAssertionCount(1);
    }
}
