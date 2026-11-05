<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\FlintTopicTaskService;
use App\Services\Knowledge\IntegrationTaskService;
use App\Services\Knowledge\TaskService;
use App\Support\SparkAbility;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeTasksControllerTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 11)->startOfDay());
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
    }

    #[Test]
    public function day_week_and_persistent_have_calendar_windows(): void
    {
        foreach (['day' => '2026-10-25', 'week' => '2026-10-31', 'persistent' => null] as $kind => $expiry) {
            $task = $this->create(['lifecycle' => $kind, 'availability_on' => '2026-10-24']);
            $this->assertSame($expiry, $task['expires_on']);
            $this->assertSame('Europe/London', $task['schedule_timezone']);
            $block = app(TaskService::class)->find($this->user, $task['id']);
            $this->assertSame($kind === 'day' ? 'expired' : 'open', app(TaskService::class)->payload($block, '2026-10-25')['status']);
        }
        $this->postJson('/api/v1/mobile/tasks', ['title' => 'Missing date', 'client_mutation_id' => (string) Str::uuid(), 'lifecycle' => 'week'])->assertUnprocessable();
    }

    #[Test]
    public function inbox_capture_is_idempotent_and_requires_versions_for_edits(): void
    {
        $input = ['title' => 'Inbox', 'client_mutation_id' => (string) Str::uuid()];
        $task = $this->create($input);
        $this->postJson('/api/v1/mobile/tasks', $input)->assertCreated()->assertJsonPath('data.id', $task['id']);
        $this->postJson('/api/v1/mobile/tasks', array_merge($input, ['title' => 'Different']))->assertConflict();
        $this->patchJson('/api/v1/mobile/tasks/' . $task['id'], ['title' => 'Edit'])->assertStatus(428);
        $mutation = (string) Str::uuid();
        $edit = ['title' => 'Edit', 'client_mutation_id' => $mutation];
        $first = $this->withHeader('If-Match', $task['version'])->patchJson('/api/v1/mobile/tasks/' . $task['id'], $edit)->assertOk();
        $this->withHeader('If-Match', $task['version'])->patchJson('/api/v1/mobile/tasks/' . $task['id'], $edit)->assertOk()->assertJsonPath('data.version', $first->json('data.version'));
        $this->withHeader('If-Match', $task['version'])->patchJson('/api/v1/mobile/tasks/' . $task['id'], ['title' => 'Stale'])->assertStatus(412);
        $this->getJson('/api/v1/mobile/tasks?inbox=1')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function deferring_week_does_not_extend_expiry_and_reactivation_does(): void
    {
        $task = $this->create(['lifecycle' => 'week', 'availability_on' => '2026-10-11']);
        $updated = $this->withHeader('If-Match', $task['version'])->patchJson('/api/v1/mobile/tasks/' . $task['id'], ['availability_on' => '2026-10-16'])->assertOk()->json('data');
        $this->assertSame('2026-10-18', $updated['expires_on']);
        $this->withHeader('If-Match', $updated['version'])->patchJson('/api/v1/mobile/tasks/' . $task['id'], ['availability_on' => '2026-10-18'])->assertUnprocessable();
        $this->withHeader('If-Match', $updated['version'])->postJson('/api/v1/mobile/tasks/' . $task['id'] . '/actions', ['action' => 'reactivate', 'availability_on' => '2026-10-20'])->assertOk()->assertJsonPath('data.expires_on', '2026-10-27');
    }

    #[Test]
    public function project_and_thread_links_enforce_ownership_and_pause_projection(): void
    {
        $project = $this->postJson('/api/v1/mobile/projects', ['title' => 'Holiday'])->assertCreated()->json('data');
        $topic = EventObject::factory()->create(['user_id' => $this->user->id, 'concept' => 'flint', 'type' => 'topic']);
        $task = $this->create(['availability_on' => '2026-10-11', 'project_id' => $project['id'], 'thread_ids' => [$topic->id]]);
        $this->assertSame([$topic->id], $task['thread_ids']);
        $this->withHeader('If-Match', $project['version'])->patchJson('/api/v1/mobile/projects/' . $project['id'], ['status' => 'paused'])->assertOk();
        $this->getJson('/api/v1/mobile/tasks?date=2026-10-11')->assertOk()->assertJsonCount(0, 'data');
        $other = User::factory()->create();
        Sanctum::actingAs($other, SparkAbility::MOBILE_SESSION);
        $this->getJson('/api/v1/mobile/tasks/' . $task['id'])->assertNotFound();
        $this->postJson('/api/v1/mobile/tasks', ['title' => 'Foreign', 'client_mutation_id' => (string) Str::uuid(), 'project_id' => $project['id']])->assertNotFound();
    }

    #[Test]
    public function completion_delete_restore_history_and_action_replay_are_shared(): void
    {
        $task = $this->create();
        $command = ['action' => 'complete', 'client_mutation_id' => (string) Str::uuid()];
        $done = $this->withHeader('If-Match', $task['version'])->postJson('/api/v1/mobile/tasks/' . $task['id'] . '/actions', $command)->assertOk()->assertJsonPath('data.status', 'completed')->json('data');
        $this->withHeader('If-Match', $task['version'])->postJson('/api/v1/mobile/tasks/' . $task['id'] . '/actions', $command)->assertOk()->assertJsonPath('data.version', $done['version']);
        $deleted = $this->withHeader('If-Match', $done['version'])->postJson('/api/v1/mobile/tasks/' . $task['id'] . '/actions', ['action' => 'delete'])->assertOk()->assertJsonPath('data.status', 'deleted')->json('data');
        $this->withHeader('If-Match', $deleted['version'])->postJson('/api/v1/mobile/tasks/' . $task['id'] . '/actions', ['action' => 'restore'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/v1/mobile/tasks/' . $task['id'] . '/history')->assertOk();
    }

    #[Test]
    public function incidents_deduplicate_and_only_verified_recovery_resolves_them(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $service = app(IntegrationTaskService::class);
        $service->report($this->user, $integration, true);
        $service->report($this->user, $integration, true);
        $block = app(TaskService::class)->query($this->user)->firstOrFail();
        $task = app(TaskService::class)->payload($block);
        $this->assertSame('integration_reauth', $task['action']['kind']);
        $this->assertSame(1, app(TaskService::class)->query($this->user)->count());
        $this->withHeader('If-Match', $task['version'])->postJson('/api/v1/mobile/tasks/' . $task['id'] . '/actions', ['action' => 'complete'])->assertConflict();
        $integration->last_successful_update_at = now()->subHour();
        $service->reconcile($integration);
        $this->assertSame('open', app(TaskService::class)->state($block->fresh())['status']);
        $integration->last_successful_update_at = now()->addMinute();
        $service->reconcile($integration);
        $this->assertSame('completed', app(TaskService::class)->state($block->fresh())['status']);
    }

    #[Test]
    public function client_neutral_api_and_web_screen_are_available(): void
    {
        $this->withoutVite();
        $this->getJson('/api/v1/tasks')->assertOk();
        $this->actingAs($this->user)->get('/knowledge/tasks')->assertOk()->assertSee('Tasks &amp; Projects', false);
    }

    #[Test]
    public function native_tasks_are_visible_and_editable_from_the_existing_thread_surface(): void
    {
        $topic = EventObject::factory()->create(['user_id' => $this->user->id, 'concept' => 'flint', 'type' => 'topic']);
        $task = $this->create(['thread_ids' => [$topic->id]]);
        $threads = app(FlintTopicTaskService::class);
        $this->assertSame($task['id'], $threads->list($this->user, $topic->id)[0]['id']);
        $edited = $threads->update($this->user, $topic->id, $task['id'], ['completed' => true], $task['version']);
        $this->assertSame('completed', $edited['status']);
        app(TaskService::class)->update($this->user, $task['id'], ['thread_ids' => []], $edited['version']);
        $this->assertSame([], $threads->list($this->user, $topic->id));
    }

    #[Test]
    public function canary_flags_disable_writes_without_hiding_existing_tasks(): void
    {
        $this->create();
        $this->user->update(['settings' => ['knowledge_features' => ['writes' => false]]]);
        $this->getJson('/api/v1/mobile/tasks')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/mobile/tasks', ['title' => 'Disabled', 'client_mutation_id' => (string) Str::uuid()])->assertNotFound();
    }

    private function create(array $data = []): array
    {
        return $this->postJson('/api/v1/mobile/tasks', $data + ['title' => 'Book a table', 'client_mutation_id' => (string) Str::uuid()])->assertCreated()->json('data');
    }
}
