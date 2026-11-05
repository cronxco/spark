<?php

namespace Tests\Feature;

use App\Jobs\Knowledge\MaintainTasks;
use App\Jobs\Knowledge\SendTaskReminder;
use App\Jobs\Knowledge\SyncOutlineTask;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Notifications\TaskReminder;
use App\Services\Knowledge\TaskLifecycle;
use App\Services\Knowledge\TaskProposalService;
use App\Services\Knowledge\TaskRecurrenceService;
use App\Services\Knowledge\TaskService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeTaskAutomationTest extends TestCase
{
    #[Test]
    public function recurrence_retains_month_end_and_deduplicates_occurrences(): void
    {
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $root = $tasks->create($user, ['title' => 'Pay rent', 'client_mutation_id' => (string) Str::uuid(), 'availability_on' => '2026-01-31', 'lifecycle' => 'day', 'recurrence' => 'monthly']);
        $this->assertSame('2026-02-28', app(TaskRecurrenceService::class)->occurrence('2026-01-31', 'monthly', 1));
        $this->assertSame('2026-03-31', app(TaskRecurrenceService::class)->occurrence('2026-01-31', 'monthly', 2));
        $series = EventObject::where('type', 'task_series')->firstOrFail();
        $this->travelTo(now()->setDate(2026, 3, 31));
        app(TaskRecurrenceService::class)->generate($series);
        app(TaskRecurrenceService::class)->generate($series);
        $this->assertSame(2, $tasks->query($user)->count());
        $this->assertSame('2026-03-31', $tasks->payload($tasks->query($user)->where('blocks.id', '!=', $root['id'])->firstOrFail())['availability_on']);
    }

    #[Test]
    public function expiry_is_recorded_and_reminders_recheck_completion(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $old = $tasks->create($user, ['title' => 'Yesterday', 'client_mutation_id' => (string) Str::uuid(), 'lifecycle' => 'day', 'availability_on' => now()->subDays(2)->toDateString()]);
        $live = $tasks->create($user, ['title' => 'Call', 'client_mutation_id' => (string) Str::uuid(), 'remind_at' => now()->subMinute()->toIso8601String()]);
        app(MaintainTasks::class)->handle($tasks, app(TaskLifecycle::class), app(TaskRecurrenceService::class));
        $this->assertSame('expired', $tasks->state($tasks->find($user, $old['id']))['status']);
        $receipt = Event::where('action', 'task_reminder')->firstOrFail();
        app(SendTaskReminder::class, ['receiptId' => (string) $receipt->id])->handle($tasks);
        Notification::assertSentTo($user, TaskReminder::class);
        $notification = new TaskReminder($live['id'], $live['remind_at'], $live['title']);
        $tasks->act($user, $live['id'], 'complete', $live['version']);
        $this->assertFalse($notification->shouldSend($user, 'mail'));
    }

    #[Test]
    public function proposals_wait_for_acceptance_and_accept_only_once(): void
    {
        $user = User::factory()->create();
        $service = app(TaskProposalService::class);
        $data = ['title' => 'Read this', 'reason' => 'It answers a question in your thread.', 'source_key' => 'thread:evidence:one'];
        $p = $service->propose($user, $data);
        $again = $service->propose($user, $data);
        $this->assertSame($p['id'], $again['id']);
        $this->assertSame(0, app(TaskService::class)->query($user)->count());
        $service->act($user, $p['id'], 'confirm', ['title' => 'Read and annotate']);
        $service->act($user, $p['id'], 'confirm');
        $this->assertSame(1, app(TaskService::class)->query($user)->count());
        $this->assertSame([], $service->items($user));
    }

    #[Test]
    public function outline_writeback_is_visibly_blocked_without_verified_remote_preconditions(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $task = app(TaskService::class)->create($user, ['title' => 'Source', 'client_mutation_id' => (string) Str::uuid()]);
        $block = app(TaskService::class)->find($user, $task['id']);
        $m = $block->metadata;
        $m['outline_document_id'] = 'doc';
        $m['outline_source_line'] = '- [ ] Source';
        $m['task']['sync'] = ['status' => 'pending', 'desired_title' => 'Edited', 'desired_checked' => true, 'desired_deleted' => false];
        $block->update(['block_type' => 'doc_task', 'metadata' => $m]);
        app(SyncOutlineTask::class, ['blockId' => (string) $block->id])->handle(app(TaskService::class));
        $this->assertSame('blocked', data_get($block->fresh()->metadata, 'task.sync.status'));
        Http::assertNothingSent();
    }

    #[Test]
    public function outline_writer_uses_a_revision_guarded_fragment_patch(): void
    {
        config(['knowledge.outline_revision_writes_verified' => true]);
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $p = $tasks->create($user, ['title' => 'Source', 'client_mutation_id' => (string) Str::uuid()]);
        $block = $tasks->find($user, $p['id']);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'outline', 'configuration' => ['api_url' => 'https://outline.test', 'access_token' => 'fake-token']]);
        $block->event->update(['integration_id' => $integration->id]);
        $m = $block->metadata;
        $m['outline_document_id'] = 'doc';
        $m['outline_source_line'] = '- [ ] Source';
        $m['task']['sync'] = ['status' => 'pending', 'desired_title' => 'Updated', 'desired_checked' => true, 'desired_deleted' => false, 'desired_content' => 'Details'];
        $block->update(['block_type' => 'doc_task', 'metadata' => $m]);
        Http::fake(['*documents.info' => Http::response(['data' => ['revision' => 12, 'text' => "# Keep formatting\n- [ ] Source\nOther text"]]), '*documents.update' => Http::response(['data' => ['revision' => 13]])]);
        (new SyncOutlineTask((string) $block->id))->handle($tasks);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'documents.update') && $request['editMode'] === 'patch' && $request['lastRevision'] === 12 && $request['findText'] === '- [ ] Source' && ! str_contains($request['text'], 'Keep formatting'));
        $this->assertSame('synced', data_get($block->fresh()->metadata, 'task.sync.status'));
    }

    #[Test]
    public function backfill_is_dry_by_default_and_rollback_preserves_later_edits(): void
    {
        $user = User::factory()->create();
        $p = app(TaskService::class)->create($user, ['title' => 'Legacy', 'client_mutation_id' => (string) Str::uuid()]);
        $block = app(TaskService::class)->find($user, $p['id']);
        $block->update(['block_type' => 'flint_topic_task', 'metadata' => ['content' => 'Keep me']]);
        Artisan::call('knowledge:tasks-backfill', ['--user' => $user->id]);
        $this->assertArrayNotHasKey('task', $block->fresh()->metadata);
        Artisan::call('knowledge:tasks-backfill', ['--user' => $user->id, '--apply' => true]);
        $event = Event::where('action', 'task_backfilled')->firstOrFail();
        $this->assertArrayHasKey('task', $block->fresh()->metadata);
        Artisan::call('knowledge:tasks-backfill', ['--rollback' => data_get($event->event_metadata, 'migration_id')]);
        $this->assertSame(['content' => 'Keep me'], $block->fresh()->metadata);
    }

    #[Test]
    public function outline_stale_revision_becomes_a_visible_conflict_without_overwriting_source(): void
    {
        config(['knowledge.outline_revision_writes_verified' => true]);
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $p = $tasks->create($user, ['title' => 'Source', 'client_mutation_id' => (string) Str::uuid()]);
        $block = $tasks->find($user, $p['id']);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'outline', 'configuration' => ['api_url' => 'https://outline.test', 'access_token' => 'fake-token']]);
        $block->event->update(['integration_id' => $integration->id]);
        $m = $block->metadata;
        $m['outline_document_id'] = 'doc';
        $m['outline_source_line'] = '- [ ] Source';
        $m['task']['sync'] = ['status' => 'pending', 'desired_title' => 'Updated', 'desired_checked' => true, 'desired_deleted' => false, 'desired_content' => null];
        $block->update(['block_type' => 'doc_task', 'metadata' => $m]);
        Http::fake(['*documents.info' => Http::response(['data' => ['revision' => 12, 'text' => '- [ ] Source']]), '*documents.update' => Http::response(['error' => 'edit_conflict'], 409)]);
        (new SyncOutlineTask((string) $block->id))->handle($tasks);
        $this->assertSame('conflict', data_get($block->fresh()->metadata, 'task.sync.status'));
        $this->assertSame('remote_revision_changed', data_get($block->fresh()->metadata, 'task.sync.reason'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function backfill_rollback_refuses_to_erase_a_subsequent_task_edit(): void
    {
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $p = $tasks->create($user, ['title' => 'Legacy', 'client_mutation_id' => (string) Str::uuid()]);
        $block = $tasks->find($user, $p['id']);
        $block->update(['block_type' => 'flint_topic_task', 'metadata' => ['content' => 'Original']]);
        Artisan::call('knowledge:tasks-backfill', ['--user' => $user->id, '--apply' => true]);
        $batch = data_get(Event::where('action', 'task_backfilled')->firstOrFail()->event_metadata, 'migration_id');
        $task = $tasks->payload($block->fresh());
        $tasks->update($user, $task['id'], ['content' => 'Later edit'], $task['version']);
        $this->assertSame(1, Artisan::call('knowledge:tasks-backfill', ['--rollback' => $batch]));
        $this->assertSame('Later edit', $tasks->payload($block->fresh())['content']);
    }
}
