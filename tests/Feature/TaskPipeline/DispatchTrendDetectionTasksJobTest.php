<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\DispatchTrendDetectionTasksJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DispatchTrendDetectionTasksJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dispatches_one_task_per_distinct_metric_combination_without_a_grouping_error(): void
    {
        Queue::fake();

        $integration = Integration::factory()->create();

        // Two events sharing a (service, action, value_unit) combination -
        // the GROUP BY query must collapse these to a single dispatch.
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value' => 80,
            'value_unit' => 'percent',
            'time' => now()->subDays(2),
        ]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value' => 85,
            'value_unit' => 'percent',
            'time' => now()->subDay(),
        ]);

        // A distinct combination gets its own dispatch.
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'hevy',
            'action' => 'had_workout',
            'value' => 45,
            'value_unit' => 'minutes',
            'time' => now()->subDay(),
        ]);

        (new DispatchTrendDetectionTasksJob)->handle();

        Queue::assertPushed(ProcessTaskPipelineJob::class, 2);
    }

    #[Test]
    public function ignores_events_without_a_value_or_value_unit(): void
    {
        Queue::fake();

        $integration = Integration::factory()->create();

        Event::factory()->create([
            'integration_id' => $integration->id,
            'value' => null,
            'value_unit' => null,
            'time' => now()->subDay(),
        ]);

        (new DispatchTrendDetectionTasksJob)->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }
}
