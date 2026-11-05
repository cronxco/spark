<?php

namespace Tests\Feature;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Knowledge\MaintainTasks;
use App\Jobs\Knowledge\SyncOutlineTask;
use App\Jobs\Outline\OutlineData;
use App\Livewire\KnowledgeTasks;
use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\KnowledgeTasksTool;
use App\Models\Block;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\FlintTopicTaskService;
use App\Services\Knowledge\IntegrationTaskService;
use App\Services\Knowledge\TaskLifecycle;
use App\Services\Knowledge\TaskProposalService;
use App\Services\Knowledge\TaskRecurrenceService;
use App\Services\Knowledge\TaskService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeTaskReviewRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(12, 0));
    }

    #[Test]
    public function conditional_outline_writes_do_not_retry_but_reads_do(): void
    {
        config(['knowledge.outline_revision_writes_verified' => true]);
        $api = new OutlineApi($this->outline(User::factory()->create()));
        Http::fake(['*documents.update' => Http::sequence()->push([], 500)->push(['error' => 'edit_conflict'], 409)]);
        try {
            $api->updateDocumentAtRevision('doc', 'updated', 12);
            $this->fail('Expected the uncertain write to fail for a queue retry.');
        } catch (Exception $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
        Http::assertSentCount(1);
        Http::fake(['*documents.info' => Http::sequence()->push([], 500)->push(['data' => ['revision' => 13]])]);
        $this->assertSame(13, $api->getDocument('doc')['data']['revision']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_lost_write_response_is_acknowledged_by_the_next_queue_attempt(): void
    {
        config(['knowledge.outline_revision_writes_verified' => true]);
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $p = $this->capture($user);
        $block = $tasks->find($user, $p['id']);
        $block->event->update(['integration_id' => $this->outline($user)->id]);
        $m = $block->metadata;
        $m['outline_document_id'] = 'doc';
        $m['outline_source_line'] = '- [ ] Source';
        $m['task']['sync'] = ['status' => 'pending', 'desired_title' => 'Updated', 'desired_checked' => true, 'desired_deleted' => false, 'desired_content' => null];
        $block->update(['block_type' => 'doc_task', 'metadata' => $m]);
        $updated = false;
        $writes = 0;
        $desired = '- [x] Updated [Open in Spark](' . route('knowledge.task', $block->id) . ')';
        Http::fake(function ($request) use (&$updated, &$writes, $desired) {
            if (str_ends_with($request->url(), 'documents.update')) {
                $writes++;
                if ($updated) {
                    return Http::response(['error' => 'edit_conflict'], 409);
                }
                $updated = true;

                return Http::response([], 500);
            }

            return Http::response(['data' => ['revision' => $updated ? 13 : 12, 'text' => $updated ? $desired : '- [ ] Source']]);
        });
        $job = new SyncOutlineTask((string) $block->id);
        try {
            $job->handle($tasks);
            $this->fail('The uncertain response must cause a queue retry.');
        } catch (Exception $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
        $this->assertSame('pending', data_get($block->fresh()->metadata, 'task.sync.status'));
        $job->handle($tasks);
        $this->assertSame('synced', data_get($block->fresh()->metadata, 'task.sync.status'));
        $this->assertSame(1, $writes);
    }

    #[Test]
    public function deleting_a_unique_fragment_with_a_duplicate_first_line_uses_its_own_neighbors(): void
    {
        config(['knowledge.outline_revision_writes_verified' => true]);
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $p = $this->capture($user);
        $block = $tasks->find($user, $p['id']);
        $block->event->update(['integration_id' => $this->outline($user)->id]);
        $m = $block->metadata;
        $m['outline_document_id'] = 'doc';
        $m['outline_source_line'] = '- [ ] Same';
        $m['outline_source_details'] = ['  > second'];
        $m['task']['sync'] = ['status' => 'pending', 'desired_title' => 'Same', 'desired_checked' => false, 'desired_deleted' => true, 'desired_content' => 'second'];
        $block->update(['block_type' => 'doc_task', 'metadata' => $m]);
        Http::fake(['*documents.info' => Http::response(['data' => ['revision' => 12, 'text' => "# First\n- [ ] Same\n  > first\n# Second\n- [ ] Same\n  > second\nEnd"]]), '*documents.update' => Http::response(['data' => ['revision' => 13]])]);
        (new SyncOutlineTask((string) $block->id))->handle($tasks);
        $this->assertSame(['previous' => '# Second', 'next' => 'End'], data_get($block->fresh()->metadata, 'outline_deleted_neighbors'));
    }

    #[Test]
    public function unmarked_duplicates_remain_ambiguous_even_when_counts_and_lines_match(): void
    {
        $integration = $this->outline(User::factory()->create());
        $doc = ['id' => 'duplicates', 'title' => 'Checklist', 'collectionId' => 'col', 'createdAt' => now()->toIso8601String(), 'text' => "- [ ] Same\n- [ ] Same"];
        (new OutlineData($integration, ['documents' => [$doc]]))->handle();
        $ids = Block::where('metadata->outline_document_id', 'duplicates')->pluck('id')->all();
        (new OutlineData($integration, ['documents' => [$doc]]))->handle();
        $blocks = Block::where('metadata->outline_document_id', 'duplicates')->get();
        $this->assertEqualsCanonicalizing($ids, $blocks->pluck('id')->all());
        foreach ($blocks as $block) {
            $this->assertSame('ambiguous_source_identity', data_get($block->metadata, 'task.sync.reason'));
        }
    }

    #[Test]
    public function web_capture_resets_every_field_after_editing(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $task = $this->capture($user, ['lifecycle' => 'week', 'availability_on' => '2026-10-12', 'due_on' => '2026-10-14', 'review_on' => '2026-10-13', 'remind_at' => '2026-10-12T12:00:00Z', 'priority' => 'important', 'position' => 7, 'content' => 'Details']);
        Livewire::actingAs($user)->test(KnowledgeTasks::class)->call('edit', $task['id'])->call('save')
            ->assertSet('editing', null)->assertSet('version', null)->assertSet('title', '')->assertSet('content', null)
            ->assertSet('lifecycle', 'persistent')->assertSet('availability', '2026-10-11')->assertSet('due', null)
            ->assertSet('review', null)->assertSet('reminder', null)->assertSet('project', null)->assertSet('threads', [])
            ->assertSet('priority', 'normal')->assertSet('recurrence', null)->assertSet('position', 0);
    }

    #[Test]
    public function mcp_reads_obey_global_and_project_rollout_flags(): void
    {
        $user = User::factory()->create();
        $task = $this->capture($user);
        config(['knowledge.features.reads' => false]);
        foreach (['list', 'show', 'projects'] as $operation) {
            SparkServer::actingAs($user)->tool(KnowledgeTasksTool::class, ['operation' => $operation, 'id' => $task['id']])->assertHasErrors();
        }
        config(['knowledge.features.reads' => true, 'knowledge.features.projects' => false]);
        SparkServer::actingAs($user)->tool(KnowledgeTasksTool::class, ['operation' => 'projects'])->assertHasErrors();
        SparkServer::actingAs($user)->tool(KnowledgeTasksTool::class, ['operation' => 'list'])->assertOk();
    }

    #[Test]
    public function consent_incidents_keep_their_recovery_condition_and_repeats_only_count(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'gocardless', 'auth_metadata' => ['gocardless_completed_reference' => 'old-consent']]);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'gocardless', 'integration_group_id' => $group->id]);
        $service = app(IntegrationTaskService::class);
        $service->report($user, $integration, true, true);
        $service->report($user, $integration, true, true);
        $service->report($user, $integration, true);
        $tasks = app(TaskService::class);
        $blocks = $tasks->query($user)->get();
        $this->assertCount(2, $blocks);
        $consent = $blocks->first(fn ($block) => data_get($block->metadata, 'task.incident.kind') === 'consent');
        $reports = $tasks->history($user, (string) $consent->id)->where('action', 'task_incident_reported')->get();
        $this->assertCount(1, $reports);
        $this->assertSame('integration', data_get($reports->first()->event_metadata, 'after.origin'));
        $this->assertSame(2, data_get($consent->metadata, 'task.incident.occurrences'));
        $integration->update(['last_successful_update_at' => now()->addMinute()]);
        $service->reconcile($integration->fresh());
        $this->assertSame('open', data_get($consent->fresh()->metadata, 'task.status'));
        $this->assertSame('completed', data_get($blocks->first(fn ($block) => $block->id !== $consent->id)->fresh()->metadata, 'task.status'));
    }

    #[Test]
    public function a_repeating_failure_counts_occurrences_without_a_history_event_each_time(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'gocardless', 'auth_metadata' => ['gocardless_completed_reference' => 'consent-a']]);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'gocardless', 'integration_group_id' => $group->id]);
        $service = app(IntegrationTaskService::class);
        foreach (range(1, 5) as $_) {
            $service->report($user, $integration, false);
        }
        $tasks = app(TaskService::class);
        $block = $tasks->query($user)->sole();
        $history = fn () => $tasks->history($user, (string) $block->id)->where('action', 'task_incident_reported')->count();
        $this->assertSame(5, data_get($block->metadata, 'task.incident.occurrences'));
        $this->assertSame(1, $history());

        // A change to the incident itself is recorded.
        $group->update(['auth_metadata' => ['gocardless_completed_reference' => 'consent-b']]);
        $service->report($user, $integration->fresh(), false);
        $this->assertSame(6, data_get($block->fresh()->metadata, 'task.incident.occurrences'));
        $this->assertSame(2, $history());
    }

    #[Test]
    public function proposal_identity_overrides_a_callers_existing_mutation_id(): void
    {
        $user = User::factory()->create();
        $mutation = (string) Str::uuid();
        $this->capture($user, ['client_mutation_id' => $mutation]);
        $proposals = app(TaskProposalService::class);
        $p = $proposals->propose($user, ['title' => 'Different task', 'reason' => 'Review this', 'source_key' => 'stable-source', 'client_mutation_id' => $mutation]);
        $proposals->act($user, $p['id'], 'confirm');
        $this->assertSame('accepted', data_get(EventObject::find($p['id'])->metadata, 'status'));
        $this->assertSame(2, app(TaskService::class)->query($user)->count());
    }

    #[Test]
    public function native_thread_tasks_have_legacy_mirrors_before_their_first_edit(): void
    {
        $user = User::factory()->create();
        $topic = EventObject::factory()->create(['user_id' => $user->id, 'concept' => 'flint', 'type' => 'topic']);
        $late = $this->capture($user, ['title' => 'Late', 'thread_ids' => [$topic->id], 'due_on' => '2026-10-15']);
        $early = $this->capture($user, ['title' => 'Early', 'thread_ids' => [$topic->id], 'due_on' => '2026-10-12', 'content' => 'Details']);
        $this->assertSame([$early['id'], $late['id']], array_column(app(FlintTopicTaskService::class)->list($user, $topic->id), 'id'));
        $m = app(TaskService::class)->find($user, $early['id'])->metadata;
        $this->assertSame('Details', $m['content']);
        $this->assertFalse($m['checked']);
        $this->assertSame('2026-10-12', $m['due_on']);
    }

    #[Test]
    public function maintenance_skips_closed_history_but_keeps_pending_source_sync(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $tasks = app(TaskService::class);
        $closed = $this->capture($user);
        $tasks->act($user, $closed['id'], 'complete', $closed['version']);
        $this->capture($user);
        $pending = $this->capture($user);
        $block = $tasks->find($user, $pending['id']);
        $state = $tasks->state($block);
        $state['status'] = 'completed';
        $state['sync'] = ['status' => 'pending'];
        $tasks->save($block, $state, $block->title);
        $mock = $this->partialMock(TaskService::class);
        $mock->shouldReceive('state')->twice()->passthru();
        app(MaintainTasks::class)->handle($mock, app(TaskLifecycle::class), app(TaskRecurrenceService::class));
        Queue::assertPushed(SyncOutlineTask::class, fn ($job) => $job->blockId === (string) $block->id);
    }

    #[Test]
    public function planned_query_excludes_unrelated_tasks_and_preserves_historical_snapshots(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'UTC']]);
        $tasks = app(TaskService::class);
        $open = $this->capture($user, ['availability_on' => '2026-10-11']);
        $this->capture($user, ['availability_on' => '2026-10-12']);
        $this->capture($user, ['availability_on' => '2026-10-09', 'lifecycle' => 'day']);
        $closed = $this->capture($user, ['availability_on' => '2026-10-09']);
        $tasks->act($user, $closed['id'], 'cancel', $closed['version']);
        $this->assertSame([$open['id']], $tasks->plannedQuery($user, '2026-10-11')->pluck('id')->all());
        $this->travelTo(now()->addDay());
        $deferred = $tasks->update($user, $open['id'], ['availability_on' => '2026-10-12'], $open['version']);
        $tasks->act($user, $open['id'], 'complete', $deferred['version']);
        $this->assertContains($open['id'], $tasks->plannedQuery($user, '2026-10-11')->pluck('id')->all());
        $this->assertTrue($tasks->payload($tasks->find($user, $open['id']), '2026-10-11')['visible_on_date']);
    }

    private function capture(User $user, array $fields = []): array
    {
        return app(TaskService::class)->create($user, array_merge(['title' => 'Task', 'client_mutation_id' => (string) Str::uuid()], $fields));
    }

    private function outline(User $user): Integration
    {
        return Integration::factory()->create(['user_id' => $user->id, 'service' => 'outline', 'configuration' => ['api_url' => 'https://outline.test', 'access_token' => 'fake-token']]);
    }
}
