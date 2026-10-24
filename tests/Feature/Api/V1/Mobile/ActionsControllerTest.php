<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Support\SparkAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A3/A4. The native face of the ActionRegistry: list, run with a receipt,
 * confirm-first, and Undo.
 */
class ActionsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
        $this->integration = $this->integrationFor($this->user);
    }

    #[Test]
    public function it_lists_native_actions_the_session_may_run(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $ids = collect($this->getJson('/api/v1/mobile/actions')->assertOk()->json('data'))->pluck('id');

        $this->assertContains('nav.today', $ids);
        $this->assertContains('events.delete', $ids);
        $this->assertNotContains('nav.activity', $ids, 'web-only actions stay off the native list');
        $this->assertContains('flint.run_digest', $ids);
    }

    #[Test]
    public function a_read_only_session_is_not_offered_actions_it_could_not_run(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_READ);

        $ids = collect($this->getJson('/api/v1/mobile/actions')->assertOk()->json('data'))->pluck('id');

        $this->assertContains('nav.today', $ids);
        $this->assertNotContains('events.delete', $ids);
        $this->assertNotContains('integrations.pause', $ids);
    }

    #[Test]
    public function the_list_filters_by_query(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $ids = collect($this->getJson('/api/v1/mobile/actions?q=pause')->assertOk()->json('data'))->pluck('id');

        $this->assertSame(['integrations.pause'], $ids->all());
    }

    #[Test]
    public function running_an_action_needs_its_capability(): void
    {
        $event = $this->eventFor($this->integration);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_READ);

        $this->postJson('/api/v1/mobile/actions/events.delete/run', ['target' => ['event_id' => $event->id]])
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'data:write');

        $this->assertNull($event->fresh()->deleted_at);
    }

    #[Test]
    public function an_unknown_or_web_only_action_is_not_found(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->postJson('/api/v1/mobile/actions/nope.nothing/run')->assertNotFound();
        $this->postJson('/api/v1/mobile/actions/nav.activity/run')->assertNotFound();
    }

    #[Test]
    public function a_navigation_action_is_left_to_the_client(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->postJson('/api/v1/mobile/actions/nav.today/run')
            ->assertStatus(422)
            ->assertJsonPath('code', 'client_action')
            ->assertJsonPath('action.destination.route', 'dashboard');

        $this->assertSame(0, ActionProgress::where('action_type', 'command')->count());
    }

    #[Test]
    public function the_target_is_validated(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->postJson('/api/v1/mobile/actions/events.delete/run', ['target' => ['event_id' => 'not-a-uuid']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    #[Test]
    public function a_cheap_reversible_action_runs_directly_and_offers_undo(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->eventFor($this->integration);

        $receipt = $this->postJson('/api/v1/mobile/actions/events.delete/run', ['target' => ['event_id' => $event->id]])
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('action_id', 'events.delete')
            ->assertJsonPath('undo.available', true)
            ->json();

        $this->assertSoftDeleted('events', ['id' => $event->id]);

        $this->postJson("/api/v1/mobile/actions/receipts/{$receipt['receipt_id']}/undo")
            ->assertOk()
            ->assertJsonPath('undo.available', false)
            ->assertJsonPath('undo.undone_at', fn ($at) => $at !== null);

        $this->assertNull($event->fresh()->deleted_at);

        // A second Undo is harmless.
        $this->postJson("/api/v1/mobile/actions/receipts/{$receipt['receipt_id']}/undo")->assertOk();
    }

    #[Test]
    public function tagging_is_undone_by_removing_only_what_it_added(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->eventFor($this->integration);
        $event->attachTag('keep');

        $first = $this->postJson('/api/v1/mobile/actions/events.tag_add/run', ['target' => ['event_id' => $event->id, 'name' => 'pub']])
            ->assertOk()->json();
        $this->assertEqualsCanonicalizing(['keep', 'pub'], $event->fresh()->tags->pluck('name')->all());

        $this->postJson("/api/v1/mobile/actions/receipts/{$first['receipt_id']}/undo")->assertOk();
        $this->assertSame(['keep'], $event->fresh()->tags->pluck('name')->all());

        // Already tagged: succeeds, but there is nothing to undo.
        $again = $this->postJson('/api/v1/mobile/actions/events.tag_add/run', ['target' => ['event_id' => $event->id, 'name' => 'keep']])
            ->assertOk()
            ->assertJsonPath('undo', null)
            ->json();

        $this->postJson("/api/v1/mobile/actions/receipts/{$again['receipt_id']}/undo")
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_undoable');
        $this->assertSame(['keep'], $event->fresh()->tags->pluck('name')->all());
    }

    #[Test]
    public function pausing_and_resuming_an_integration_can_be_undone(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $paused = $this->postJson('/api/v1/mobile/actions/integrations.pause/run', ['target' => ['integration_id' => $this->integration->id]])
            ->assertOk()->json();
        $this->assertTrue($this->integration->fresh()->isPaused());

        $this->postJson("/api/v1/mobile/actions/receipts/{$paused['receipt_id']}/undo")->assertOk();
        $this->assertFalse($this->integration->fresh()->isPaused());

        $this->postJson('/api/v1/mobile/actions/integrations.resume/run', ['target' => ['integration_id' => $this->integration->id]])
            ->assertOk()
            ->assertJsonPath('undo', null);
    }

    #[Test]
    public function a_costly_action_asks_first_and_runs_once_confirmed(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->eventFor($this->integration);
        $body = ['target' => ['event_id' => $event->id]];

        $this->postJson('/api/v1/mobile/actions/events.reprocess/run', $body)
            ->assertStatus(409)
            ->assertJsonPath('code', 'confirmation_required')
            ->assertJsonPath('action.confirm', 'confirm');

        $this->assertSame(0, ActionProgress::where('action_type', 'command')->count(), 'nothing is recorded until the user confirms');

        // Confirmed, it runs. A Spotify event is not reprocessable, so the receipt says so.
        $this->postJson('/api/v1/mobile/actions/events.reprocess/run', [...$body, 'confirmed' => true])
            ->assertStatus(422)
            ->assertJsonPath('action_id', 'events.reprocess')
            ->assertJsonPath('error.code', 'invalid');

        $this->assertSame(1, ActionProgress::where('action_type', 'command')->count());
    }

    #[Test]
    public function a_flint_routine_can_be_run_from_the_app(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->postJson('/api/v1/mobile/actions/flint.run_topics/run')
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('result.queued', true);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_READ);
        $this->postJson('/api/v1/mobile/actions/flint.run_topics/run')->assertForbidden();
    }

    #[Test]
    public function a_queued_action_reports_accepted_not_succeeded(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);

        $this->postJson('/api/v1/mobile/actions/integrations.sync/run', ['target' => ['integration_id' => $this->integration->id]])
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('undo', null);
    }

    #[Test]
    public function an_action_that_cannot_be_carried_out_returns_a_failed_receipt(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $other = $this->eventFor($this->integrationFor(User::factory()->create()));

        $receipt = $this->postJson('/api/v1/mobile/actions/events.delete/run', ['target' => ['event_id' => $other->id]])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error.code', 'not_found')
            ->json();

        $this->assertNull($other->fresh()->deleted_at);

        $this->getJson("/api/v1/mobile/actions/receipts/{$receipt['receipt_id']}")
            ->assertOk()
            ->assertJsonPath('status', 'failed');
    }

    #[Test]
    public function an_idempotency_key_replays_the_first_receipt(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->eventFor($this->integration);
        $body = ['target' => ['event_id' => $event->id, 'name' => 'pub']];

        $first = $this->postJson('/api/v1/mobile/actions/events.tag_add/run', $body, ['Idempotency-Key' => 'abc'])->assertOk()->json();
        $second = $this->postJson('/api/v1/mobile/actions/events.tag_add/run', $body, ['Idempotency-Key' => 'abc'])->assertOk()->json();

        $this->assertSame($first['receipt_id'], $second['receipt_id']);
        $this->assertSame(1, ActionProgress::where('action_type', 'command')->count());
    }

    #[Test]
    public function undo_expires(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->eventFor($this->integration);

        $receipt = $this->postJson('/api/v1/mobile/actions/events.delete/run', ['target' => ['event_id' => $event->id]])->json();

        $this->travel(16)->minutes();

        $this->postJson("/api/v1/mobile/actions/receipts/{$receipt['receipt_id']}/undo")
            ->assertStatus(422)
            ->assertJsonPath('code', 'undo_expired');
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }

    #[Test]
    public function receipts_belong_to_their_owner(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->eventFor($this->integration);
        $receipt = $this->postJson('/api/v1/mobile/actions/events.delete/run', ['target' => ['event_id' => $event->id]])->json();

        Sanctum::actingAs(User::factory()->create(), SparkAbility::MOBILE_SESSION);

        $this->getJson("/api/v1/mobile/actions/receipts/{$receipt['receipt_id']}")->assertNotFound();
        $this->postJson("/api/v1/mobile/actions/receipts/{$receipt['receipt_id']}/undo")->assertNotFound();
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }

    #[Test]
    public function receipts_stay_out_of_the_notification_activity_feed(): void
    {
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
        $event = $this->eventFor($this->integration);
        $this->postJson('/api/v1/mobile/actions/events.delete/run', ['target' => ['event_id' => $event->id]])->assertOk();

        $this->assertSame(0, ActionProgress::query()->activity()->where('user_id', $this->user->id)->count());
    }

    private function integrationFor(User $user): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);

        return Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'spotify']);
    }

    private function eventFor(Integration $integration): Event
    {
        return Event::factory()->create(['integration_id' => $integration->id, 'service' => 'spotify']);
    }
}
