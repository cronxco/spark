<?php

namespace Tests\Unit\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\GenerateEmbeddingTask;
use App\Models\Event;
use App\Models\IntegrationGroup;
use App\Models\TaskExecution;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskExecutionStoreTest extends TestCase
{
    #[Test]
    public function reads_table_rows_before_legacy_metadata(): void
    {
        $event = Event::factory()->create([
            'event_metadata' => [
                'task_executions' => [
                    'generate_embedding' => [
                        'last_attempt' => ['status' => 'failed', 'error' => 'legacy'],
                    ],
                ],
            ],
        ]);

        TaskExecution::factory()->create([
            'user_id' => $event->integration->user_id,
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'task_key' => 'generate_embedding',
            'status' => 'success',
            'attempts' => 2,
            'task_name' => 'Generate Embedding',
        ]);

        $executions = app(TaskExecutionStore::class)->getTaskExecutions($event->refresh());

        $this->assertSame('success', $executions['generate_embedding']['last_attempt']['status']);
        $this->assertSame(2, $executions['generate_embedding']['last_attempt']['attempts']);
    }

    #[Test]
    public function falls_back_to_legacy_metadata_when_table_has_no_rows(): void
    {
        $event = Event::factory()->create([
            'event_metadata' => [
                'task_executions' => [
                    'legacy_task' => [
                        'last_attempt' => ['status' => 'success'],
                    ],
                ],
            ],
        ]);

        $executions = app(TaskExecutionStore::class)->getTaskExecutions($event);

        $this->assertSame('success', $executions['legacy_task']['last_attempt']['status']);
    }

    #[Test]
    public function records_status_to_table_metadata_last_success_and_history(): void
    {
        $event = Event::factory()->create();
        $task = new TaskDefinition(
            key: 'generate_embedding',
            name: 'Generate Embedding',
            description: 'Generate AI embedding',
            jobClass: GenerateEmbeddingTask::class,
            appliesTo: ['event'],
        );

        $store = app(TaskExecutionStore::class);
        $store->recordStatus($event, $task, 'pending', [
            'started_at' => now()->toIso8601String(),
            'triggered_by' => 'created',
        ], mergeLastAttempt: false);
        $store->recordStatus($event, $task, 'success', [
            'completed_at' => now()->toIso8601String(),
            'attempts' => 1,
        ]);

        $row = TaskExecution::where('entity_type', 'event')
            ->where('entity_id', $event->id)
            ->where('task_key', 'generate_embedding')
            ->firstOrFail();

        $this->assertSame('success', $row->status);
        $this->assertSame('success', $row->last_success['status']);
        $this->assertCount(1, $row->history);

        $metadata = $event->refresh()->event_metadata['task_executions']['generate_embedding'];
        $this->assertSame('success', $metadata['last_attempt']['status']);
        $this->assertSame('success', $metadata['last_success']['status']);
    }

    #[Test]
    public function records_status_for_an_integration_group_anchor(): void
    {
        $group = IntegrationGroup::factory()->create();
        $task = new TaskDefinition(
            key: 'check_cookie_expiry_example_com',
            name: 'Check Cookie Expiry: example.com',
            description: 'Warn the user before fetch cookies expire',
            jobClass: 'App\\Jobs\\Fetch\\CheckCookieExpiryJob',
            appliesTo: ['integration_group'],
        );

        $store = app(TaskExecutionStore::class);
        $store->recordStatus($group, $task, 'success', [
            'domain' => 'example.com',
            'notification_sent' => true,
        ]);

        $row = TaskExecution::where('entity_type', 'integration_group')
            ->where('entity_id', $group->id)
            ->where('task_key', 'check_cookie_expiry_example_com')
            ->firstOrFail();

        $this->assertSame('success', $row->status);
        $this->assertSame($group->user_id, $row->user_id);

        $metadata = $group->refresh()->auth_metadata['task_executions']['check_cookie_expiry_example_com'];
        $this->assertSame('success', $metadata['last_attempt']['status']);
    }
}
