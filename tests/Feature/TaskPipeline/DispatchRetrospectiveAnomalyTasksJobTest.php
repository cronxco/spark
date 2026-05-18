<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\DispatchRetrospectiveAnomalyTasksJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DispatchRetrospectiveAnomalyTasksJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function skips_events_with_successful_anomaly_detection_in_event_metadata(): void
    {
        Queue::fake();

        $integration = Integration::factory()->create();

        // Event yesterday, value+unit set, already successfully processed
        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => now()->subDay()->midDay(),
            'value' => 100,
            'value_unit' => 'kcal',
            'event_metadata' => [
                'task_executions' => [
                    'detect_anomalies' => [
                        'last_attempt' => ['status' => 'success'],
                    ],
                ],
            ],
        ]);

        (new DispatchRetrospectiveAnomalyTasksJob)->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function dispatches_for_events_without_successful_anomaly_detection(): void
    {
        Queue::fake();

        $integration = Integration::factory()->create();

        // Event yesterday, value+unit set, no task execution recorded
        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => now()->subDay()->midDay(),
            'value' => 100,
            'value_unit' => 'kcal',
            'event_metadata' => [],
        ]);

        (new DispatchRetrospectiveAnomalyTasksJob)->handle();

        Queue::assertPushed(ProcessTaskPipelineJob::class);
    }

    #[Test]
    public function does_not_dispatch_for_events_outside_yesterday(): void
    {
        Queue::fake();

        $integration = Integration::factory()->create();

        // Event from two days ago (outside yesterday window)
        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => now()->subDays(2)->midDay(),
            'value' => 100,
            'value_unit' => 'kcal',
            'event_metadata' => [],
        ]);

        (new DispatchRetrospectiveAnomalyTasksJob)->handle();

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }
}
