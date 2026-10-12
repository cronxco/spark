<?php

namespace Tests\Feature\Admin;

use App\Jobs\DeleteBinItemsBatch;
use App\Jobs\GenerateEventEmbeddingJob;
use App\Jobs\Outline\GenerateDayNotes;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Livewire\LogViewer;
use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\TaskExecution;
use App\Models\User;
use App\Notifications\AdminPushNotification;
use App\Services\DuplicateDetectionService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\IsolatesLogs;
use Tests\TestCase;

/**
 * Admin audit PR 1: tenancy and correctness fixes on pages that were reading
 * or acting on more than the viewed tenant's data.
 */
class AdminPageSafetyTest extends TestCase
{
    use IsolatesLogs;
    use RefreshDatabase;

    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->other = User::factory()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function log_viewer_refuses_another_users_user_logs(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(LogViewer::class, ['type' => 'user', 'entityId' => $this->other->id])
            ->assertForbidden();
    }

    #[Test]
    public function log_viewer_refuses_another_users_group(): void
    {
        $foreignGroup = IntegrationGroup::factory()->create(['user_id' => $this->other->id, 'service' => 'test']);

        $this->actingAs($this->admin);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(LogViewer::class, ['type' => 'group', 'entityId' => $foreignGroup->id]);
    }

    #[Test]
    public function log_viewer_refuses_another_users_integration(): void
    {
        $foreignIntegration = Integration::factory()->create(['user_id' => $this->other->id, 'service' => 'test']);

        $this->actingAs($this->admin);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(LogViewer::class, ['type' => 'integration', 'entityId' => $foreignIntegration->id]);
    }

    #[Test]
    public function log_viewer_type_and_entity_cannot_be_changed_from_the_browser(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test(LogViewer::class, ['type' => 'user']);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('entityId', $this->other->id);
    }

    #[Test]
    public function log_viewer_falls_back_to_today_for_a_malformed_date_and_caps_page_size(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(LogViewer::class, ['type' => 'user'])
            ->set('date', '../../etc/passwd')
            ->assertSet('date', now()->format('Y-m-d'))
            ->set('perPage', 1000000)
            ->assertSet('perPage', 250);
    }

    #[Test]
    public function blocks_gallery_only_shows_the_viewed_tenants_blocks(): void
    {
        $own = Block::factory()->create(['event_id' => $this->eventFor($this->admin)->id, 'block_type' => 'own_block_type']);
        $foreign = Block::factory()->create(['event_id' => $this->eventFor($this->other)->id, 'block_type' => 'foreign_block_type']);

        $this->actingAs($this->admin);

        $ids = Volt::test('admin.blocks')
            ->set('view', 'gallery')
            ->assertOk()
            ->instance()
            ->getGalleryBlocks()
            ->pluck('id')
            ->all();

        $this->assertSame([$own->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function old_block_view_url_redirects_to_the_gallery(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.block-view.index'))
            ->assertRedirect('/admin/blocks?view=gallery');
    }

    #[Test]
    public function activity_page_only_lists_the_viewed_tenants_rows(): void
    {
        $ownEvent = $this->eventFor($this->admin);
        $foreignEvent = $this->eventFor($this->other);

        $own = activity('changelog')->performedOn($ownEvent)->causedBy($this->admin)->event('updated')->log('own change');
        $foreign = activity('changelog')->performedOn($foreignEvent)->causedBy($this->other)->event('updated')->log('foreign change');
        $security = activity('security')->performedOn($this->other)->causedBy($this->other)->event('login')->log('foreign login');

        $this->actingAs($this->admin);

        $component = Volt::test('admin.activity');
        $ids = collect($component->instance()->getActivities()->items())->pluck('id')->all();

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertNotContains($security->id, $ids);

        $component->call('showActivityDetails', (string) $foreign->id)->assertSet('selectedActivity', null);
    }

    #[Test]
    public function bin_row_actions_only_touch_that_row(): void
    {
        $first = $this->eventFor($this->admin);
        $second = $this->eventFor($this->admin);
        $first->delete();
        $second->delete();

        $this->actingAs($this->admin);

        Volt::test('admin.bin')->call('bulkRestore', $first->id);

        $this->assertNotSoftDeleted($first);
        $this->assertSoftDeleted($second);

        Volt::test('admin.bin')->call('bulkDelete', $second->id);

        $this->assertModelMissing($second);
        $this->assertNotSoftDeleted($first);
    }

    #[Test]
    public function bin_permanent_delete_removes_already_trashed_blocks_of_an_event(): void
    {
        $event = $this->eventFor($this->admin);
        $block = Block::factory()->create(['event_id' => $event->id]);
        $block->delete();
        $event->delete();

        $this->actingAs($this->admin);

        Volt::test('admin.bin')->call('bulkDelete', $event->id);

        $this->assertModelMissing($event);
        $this->assertModelMissing($block);
    }

    #[Test]
    public function bin_selection_is_cleared_when_filters_change(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.bin')
            ->set('selectedItems', ['abc'])
            ->set('search', 'x')
            ->assertSet('selectedItems', []);
    }

    #[Test]
    public function delete_all_job_is_unique_per_user_until_processing(): void
    {
        $job = new DeleteBinItemsBatch($this->admin->id);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame($this->admin->id, $job->uniqueId());
    }

    #[Test]
    public function search_analytics_renders_with_uuid_user_ids(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.search')->assertOk();
    }

    #[Test]
    public function search_analytics_ignores_unknown_sort_columns(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.search')
            ->set('sortBy', ['column' => 'not_a_column; drop', 'direction' => 'sideways'])
            ->assertOk();
    }

    #[Test]
    public function embedding_generation_can_be_limited_to_one_user(): void
    {
        Queue::fake();

        $own = $this->eventFor($this->admin);
        $foreign = $this->eventFor($this->other);

        $this->artisan('embeddings:generate', ['--type' => 'events', '--user' => $this->admin->id, '--limit' => 5])
            ->assertSuccessful();

        Queue::assertPushed(GenerateEventEmbeddingJob::class, 1);
        Queue::assertNotPushed(GenerateEventEmbeddingJob::class, fn ($job) => $job->event->is($foreign));
        $this->assertTrue($own->exists);
    }

    #[Test]
    public function duplicate_detection_handles_blocks_and_bounds_its_inputs(): void
    {
        $this->eventFor($this->admin);

        $service = app(DuplicateDetectionService::class);

        $this->assertCount(0, $service->findDuplicateBlocks((string) $this->admin->id, 0.95, 10));
        $this->assertCount(0, $service->findDuplicateEvents((string) $this->admin->id, 0.95, 10));

        $this->actingAs($this->admin);

        Volt::test('admin.duplicates.index')
            ->set('modelType', 'Block')
            ->set('threshold', 0)
            ->set('limit', 999999)
            ->call('search')
            ->assertSet('threshold', 0.8)
            ->assertSet('limit', 500)
            ->assertSet('searched', true);
    }

    #[Test]
    public function push_confirmation_is_withdrawn_when_the_text_is_edited(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.push-notifications')
            ->set('title', 'Hello')
            ->set('message', 'World')
            ->call('sendNotification')
            ->assertSet('confirmSend', true)
            ->set('message', 'Something else')
            ->assertSet('confirmSend', false);
    }

    #[Test]
    public function push_broadcast_is_queued_not_sent_inline(): void
    {
        Notification::fake();

        $subscribed = User::factory()->create();
        $subscribed->updatePushSubscription('https://example.test/endpoint', 'key', 'token', 'aesgcm');

        $this->actingAs($this->admin);

        Volt::test('admin.push-notifications')
            ->set('title', 'Hello')
            ->set('message', 'World')
            ->call('sendNotification')
            ->call('sendNotification');

        Notification::assertSentTo($subscribed, AdminPushNotification::class);
        $this->assertInstanceOf(ShouldQueue::class, new AdminPushNotification('t', 'm'));
    }

    #[Test]
    public function failed_day_note_generation_is_marked_failed_so_it_can_be_retried(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->admin->id, 'service' => 'outline']);
        $progress = ActionProgress::createProgress(
            (string) $this->admin->id,
            'outline_generate_daynotes',
            'year_' . now()->year,
            'starting',
            'x',
            0,
            100,
            ['year' => now()->year]
        );

        (new GenerateDayNotes($integration, now()->year, (string) $progress->id))->failed(new RuntimeException('boom'));

        $this->assertTrue($progress->fresh()->isFailed());
        $this->assertFalse($progress->fresh()->isInProgress());
    }

    #[Test]
    public function day_notes_only_generate_current_and_next_years(): void
    {
        $this->actingAs($this->admin);

        $component = Volt::test('admin.daynotes');

        $this->assertSame([(int) now()->year, (int) now()->year + 1, (int) now()->year + 2], $component->instance()->years());
        $component->call('generateYear', 1999);

        $this->assertSame(0, ActionProgress::count());
    }

    #[Test]
    public function pipeline_retry_only_accepts_failed_executions_of_registered_tasks(): void
    {
        Queue::fake();

        $event = $this->eventFor($this->admin);
        $running = TaskExecution::factory()->create([
            'user_id' => $this->admin->id,
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'task_key' => 'generate_embedding',
            'status' => 'processing',
        ]);
        $unregistered = TaskExecution::factory()->create([
            'user_id' => $this->admin->id,
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'task_key' => 'definitely_not_a_task',
            'status' => 'failed',
        ]);

        $this->actingAs($this->admin);

        Volt::test('admin.task-pipeline-overview')
            ->call('retryFailure', $running->id)
            ->call('retryFailure', $unregistered->id);

        Queue::assertNotPushed(ProcessTaskPipelineJob::class);
    }

    private function eventFor(User $user): Event
    {
        return Event::factory()->create([
            'integration_id' => Integration::factory()->create(['user_id' => $user->id])->id,
        ]);
    }
}
