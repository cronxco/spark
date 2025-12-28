<?php

namespace Tests\Unit;

use App\Jobs\TaskPipeline\GenerateSummaryJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Place;
use App\Models\User;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPipelineInheritanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function task_definition_supports_model_inheritance(): void
    {
        $user = User::factory()->create();

        // Create a task that applies to 'object' type
        $task = new TaskDefinition(
            key: 'test_object_task',
            name: 'Test Object Task',
            description: 'Test task for objects',
            jobClass: GenerateSummaryJob::class,
            appliesTo: ['object'],
        );

        // Create a regular EventObject
        $eventObject = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'test',
            'type' => 'test_type',
            'title' => 'Test Object',
            'time' => now(),
        ]);

        // Create a Place (which extends EventObject)
        $place = Place::create([
            'user_id' => $user->id,
            'type' => 'visited',
            'title' => 'Test Place',
            'time' => now(),
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        // Both should be recognized as 'object' type
        $this->assertTrue($task->isApplicableTo($eventObject));
        $this->assertTrue($task->isApplicableTo($place));
    }

    /**
     * @test
     */
    public function task_definition_rejects_non_applicable_models(): void
    {
        $user = User::factory()->create();

        // Create a task that applies only to 'event' type
        $task = new TaskDefinition(
            key: 'test_event_task',
            name: 'Test Event Task',
            description: 'Test task for events only',
            jobClass: GenerateSummaryJob::class,
            appliesTo: ['event'],
        );

        // Create a Place
        $place = Place::create([
            'user_id' => $user->id,
            'type' => 'visited',
            'title' => 'Test Place',
            'time' => now(),
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        // Place should not be applicable to event-only task
        $this->assertFalse($task->isApplicableTo($place));
    }

    /**
     * @test
     */
    public function task_definition_handles_place_with_conditions(): void
    {
        $user = User::factory()->create();

        // Create a task for objects with specific type
        $task = new TaskDefinition(
            key: 'test_visited_task',
            name: 'Test Visited Task',
            description: 'Test task for visited places',
            jobClass: GenerateSummaryJob::class,
            appliesTo: ['object'],
            conditions: ['type' => 'visited'],
        );

        // Create places with different types
        $visitedPlace = Place::create([
            'user_id' => $user->id,
            'type' => 'visited',
            'title' => 'Visited Place',
            'time' => now(),
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        $homePlace = Place::create([
            'user_id' => $user->id,
            'type' => 'home',
            'title' => 'Home Place',
            'time' => now(),
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        // Only visited place should match
        $this->assertTrue($task->isApplicableTo($visitedPlace));
        $this->assertFalse($task->isApplicableTo($homePlace));
    }
}
