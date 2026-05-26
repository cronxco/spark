<?php

namespace Tests\Feature\TaskPipeline;

use App\Jobs\TaskPipeline\MigrateTaskExecutionsBatchJob;
use App\Models\Event;
use App\Models\TaskExecution;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MigrateTaskExecutionsCommandTest extends TestCase
{
    #[Test]
    public function dry_run_counts_legacy_metadata_without_dispatching_jobs(): void
    {
        Queue::fake();

        Event::factory()->create([
            'event_metadata' => [
                'task_executions' => [
                    'generate_embedding' => [
                        'last_attempt' => ['status' => 'success'],
                    ],
                ],
            ],
        ]);

        $this->artisan('task-pipeline:migrate-executions --model=event --dry-run')
            ->assertSuccessful();

        Queue::assertNotPushed(MigrateTaskExecutionsBatchJob::class);
    }

    #[Test]
    public function dispatches_batch_job_to_migration_queue(): void
    {
        Queue::fake();

        $this->artisan('task-pipeline:migrate-executions --model=event --batch-size=25 --limit=50')
            ->assertSuccessful();

        Queue::assertPushedOn('migration', MigrateTaskExecutionsBatchJob::class, function (MigrateTaskExecutionsBatchJob $job) {
            return $job->modelType === 'event'
                && $job->batchSize === 25
                && $job->limit === 50
                && $job->force === false;
        });
    }

    #[Test]
    public function batch_job_preserves_existing_rows_unless_forced(): void
    {
        $event = Event::factory()->create([
            'event_metadata' => [
                'task_executions' => [
                    'generate_embedding' => [
                        'last_attempt' => ['status' => 'success', 'attempts' => 1],
                    ],
                ],
            ],
        ]);

        $existing = TaskExecution::factory()->create([
            'user_id' => $event->integration->user_id,
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'task_key' => 'generate_embedding',
            'status' => 'failed',
        ]);

        (new MigrateTaskExecutionsBatchJob('event', null, 1, 1, false))
            ->handle(app(TaskExecutionStore::class));

        $this->assertSame('failed', $existing->refresh()->status);

        (new MigrateTaskExecutionsBatchJob('event', null, 1, 1, true))
            ->handle(app(TaskExecutionStore::class));

        $this->assertSame('success', $existing->refresh()->status);
    }

    #[Test]
    public function batch_job_chains_next_batch_on_migration_queue(): void
    {
        Queue::fake();

        Event::factory()->count(2)->create([
            'event_metadata' => [
                'task_executions' => [
                    'generate_embedding' => [
                        'last_attempt' => ['status' => 'success', 'attempts' => 1],
                    ],
                ],
            ],
        ]);

        (new MigrateTaskExecutionsBatchJob('event', null, 1, 2, false))
            ->handle(app(TaskExecutionStore::class));

        Queue::assertPushedOn('migration', MigrateTaskExecutionsBatchJob::class, function (MigrateTaskExecutionsBatchJob $job) {
            return $job->modelType === 'event'
                && $job->cursor !== null
                && $job->batchSize === 1
                && $job->limit === 2;
        });
    }
}
