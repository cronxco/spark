<?php

namespace Tests\Feature\Admin;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-02b, final gap.
 *
 * The sense-check page issued eighteen unscoped read queries — orphan hunts,
 * embedding-coverage counts and the action/block/object type catalogues — so
 * any admin saw every tenant's data. The `admin` middleware gates power-user
 * tooling, not cross-tenant access: every sibling page under
 * resources/views/livewire/admin/ already scopes to the signed-in user.
 */
class SenseCheckTenancyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->victim = User::factory()->create();
    }

    #[Test]
    public function embedding_counts_exclude_another_users_records(): void
    {
        $this->eventFor($this->victim);
        $this->eventFor($this->victim);
        $ownEvent = $this->eventFor($this->admin);

        Block::factory()->create(['event_id' => $this->eventFor($this->victim)->id]);
        Block::factory()->create(['event_id' => $ownEvent->id]);

        EventObject::factory()->create(['user_id' => $this->victim->id]);
        EventObject::factory()->create(['user_id' => $this->admin->id]);

        $health = Volt::actingAs($this->admin)
            ->test('admin.sense-check')
            ->instance()
            ->getEmbeddingHealthProperty();

        $this->assertSame(1, $health['events']['total']);
        $this->assertSame(1, $health['blocks']['total']);
        $this->assertSame(1, $health['objects']['total']);
    }

    #[Test]
    public function coverage_by_service_excludes_another_users_services(): void
    {
        $this->eventFor($this->victim, ['service' => 'monzo', 'domain' => 'money']);
        $this->eventFor($this->admin, ['service' => 'oura', 'domain' => 'health']);

        $health = Volt::actingAs($this->admin)
            ->test('admin.sense-check')
            ->instance()
            ->getEmbeddingHealthProperty();

        $this->assertSame(['oura'], array_column($health['events_by_service'], 'service'));
        $this->assertSame(['health'], array_column($health['events_by_domain'], 'domain'));
    }

    #[Test]
    public function orphaned_objects_exclude_another_users_objects(): void
    {
        EventObject::factory()->create(['user_id' => $this->victim->id]);
        $ownObject = EventObject::factory()->create(['user_id' => $this->admin->id]);

        $orphans = Volt::actingAs($this->admin)
            ->test('admin.sense-check')
            ->instance()
            ->getOrphanedObjectsProperty();

        $this->assertSame(1, $orphans['count']);
        $this->assertSame($ownObject->id, $orphans['records']->first()->id);
    }

    #[Test]
    public function orphaned_events_are_attributed_by_their_actor_object(): void
    {
        // Event::integration() is a withTrashed() relation, so an event is only
        // orphaned once the integration row is gone outright — and that takes
        // its user_id with it. events.integration_id is NOT NULL behind a
        // RESTRICT foreign key, so the only way to reach this state is the
        // referential corruption the check exists to find; dropping the
        // constraint inside the test transaction reproduces it faithfully.
        DB::statement('ALTER TABLE events DROP CONSTRAINT events_integration_id_foreign');

        $victimEvent = $this->orphanedEventFor($this->victim);
        $ownEvent = $this->orphanedEventFor($this->admin);

        $orphans = Volt::actingAs($this->admin)
            ->test('admin.sense-check')
            ->instance()
            ->getOrphanedEventsProperty();

        $ids = $orphans['records']->pluck('id')->all();

        $this->assertContains($ownEvent->id, $ids);
        $this->assertNotContains($victimEvent->id, $ids);
        $this->assertSame(1, $orphans['count']);
    }

    #[Test]
    public function a_dangling_block_is_attributed_to_nobody(): void
    {
        // A block carries no user_id and no foreign key but event_id, so once
        // the event row is gone there is no ownership signal left at all. It
        // must therefore be shown to no tenant rather than to every tenant.
        DB::statement('ALTER TABLE blocks DROP CONSTRAINT blocks_event_id_foreign');

        $event = $this->eventFor($this->admin);
        $block = Block::factory()->create(['event_id' => $event->id]);

        Event::withTrashed()->whereKey($event->id)->forceDelete();

        foreach ([$this->admin, $this->victim] as $viewer) {
            $orphans = Volt::actingAs($viewer)
                ->test('admin.sense-check')
                ->instance()
                ->getOrphanedBlocksProperty();

            $this->assertNotContains($block->id, $orphans['records']->pluck('id')->all());
        }
    }

    #[Test]
    public function invalid_integrations_exclude_another_users_integrations(): void
    {
        Integration::factory()->create([
            'user_id' => $this->victim->id,
            'service' => 'not-a-real-plugin',
        ]);

        $issues = Volt::actingAs($this->admin)
            ->test('admin.sense-check')
            ->instance()
            ->getInvalidIntegrationsProperty();

        $this->assertSame([], $issues);
    }

    #[Test]
    public function the_type_catalogues_exclude_another_users_data(): void
    {
        $victimEvent = $this->eventFor($this->victim, [
            'service' => 'monzo',
            'action' => 'card_payment_to',
        ]);
        Block::factory()->create([
            'event_id' => $victimEvent->id,
            'block_type' => 'victim_only_block',
        ]);

        $component = Volt::actingAs($this->admin)->test('admin.sense-check');

        $this->assertArrayNotHasKey('monzo', $component->get('dbActionsByService'));
        $this->assertArrayNotHasKey('monzo', $component->get('dbBlockTypesByService'));
        $this->assertArrayNotHasKey('monzo', $component->get('dbObjectTypesByService'));
        $this->assertArrayNotHasKey('victim_only_block', $component->get('blockCountsByType'));
    }

    /**
     * An event whose integration row has gone, leaving only its actor object.
     */
    private function orphanedEventFor(User $user): Event
    {
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => EventObject::factory()->create(['user_id' => $user->id])->id,
        ]);

        Integration::withTrashed()->whereKey($integration->id)->forceDelete();

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eventFor(User $user, array $attributes = []): Event
    {
        return Event::factory()->create(array_merge([
            'integration_id' => Integration::factory()->create(['user_id' => $user->id])->id,
        ], $attributes));
    }
}
