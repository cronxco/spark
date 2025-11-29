<?php

namespace Tests\Unit\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\TaskPipeline\TaskDefinition;
use Tests\TestCase;

class TaskDefinitionTest extends TestCase
{
    /**
     * @test
     */
    public function is_applicable_to_correct_model_type(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event', 'block'],
        );

        $event = new Event;
        $block = new Block;
        $object = new EventObject;

        $this->assertTrue($task->isApplicableTo($event));
        $this->assertTrue($task->isApplicableTo($block));
        $this->assertFalse($task->isApplicableTo($object));
    }

    /**
     * @test
     */
    public function checks_single_condition(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => 'monzo',
            ],
        );

        $event1 = new Event(['service' => 'monzo']);
        $event2 = new Event(['service' => 'spotify']);

        $this->assertTrue($task->isApplicableTo($event1));
        $this->assertFalse($task->isApplicableTo($event2));
    }

    /**
     * @test
     */
    public function checks_array_condition(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => ['monzo', 'gocardless'],
            ],
        );

        $event1 = new Event(['service' => 'monzo']);
        $event2 = new Event(['service' => 'gocardless']);
        $event3 = new Event(['service' => 'spotify']);

        $this->assertTrue($task->isApplicableTo($event1));
        $this->assertTrue($task->isApplicableTo($event2));
        $this->assertFalse($task->isApplicableTo($event3));
    }

    /**
     * @test
     */
    public function checks_multiple_conditions(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => 'monzo',
                'domain' => 'money',
            ],
        );

        $event1 = new Event(['service' => 'monzo', 'domain' => 'money']);
        $event2 = new Event(['service' => 'monzo', 'domain' => 'health']);
        $event3 = new Event(['service' => 'spotify', 'domain' => 'money']);

        $this->assertTrue($task->isApplicableTo($event1));
        $this->assertFalse($task->isApplicableTo($event2));
        $this->assertFalse($task->isApplicableTo($event3));
    }

    /**
     * @test
     */
    public function checks_custom_should_run_callback(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            shouldRun: fn ($model) => $model->value > 100,
        );

        $event1 = new Event(['value' => 150]);
        $event2 = new Event(['value' => 50]);

        $this->assertTrue($task->isApplicableTo($event1));
        $this->assertFalse($task->isApplicableTo($event2));
    }

    /**
     * @test
     */
    public function combines_conditions_and_callback(): void
    {
        $task = new TaskDefinition(
            key: 'test_task',
            name: 'Test Task',
            description: 'A test task',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
            conditions: [
                'service' => 'monzo',
            ],
            shouldRun: fn ($model) => $model->value > 100,
        );

        $event1 = new Event(['service' => 'monzo', 'value' => 150]);
        $event2 = new Event(['service' => 'monzo', 'value' => 50]);
        $event3 = new Event(['service' => 'spotify', 'value' => 150]);

        $this->assertTrue($task->isApplicableTo($event1));
        $this->assertFalse($task->isApplicableTo($event2)); // Wrong value
        $this->assertFalse($task->isApplicableTo($event3)); // Wrong service
    }
}
