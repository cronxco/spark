<?php

namespace Tests\Feature\Admin;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-02b / APO-03.
 *
 * The admin components list records scoped to the signed-in user but their bulk
 * handlers issued unscoped `whereIn` deletes driven by a public Livewire
 * property. A tampered `$selected*` array therefore deleted another tenant's
 * records. Ownership must be re-resolved inside the mutation, not trusted from
 * the request.
 */
class AdminBulkMutationTenancyTest extends TestCase
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

    private function eventFor(User $user): Event
    {
        return Event::factory()->create([
            'integration_id' => Integration::factory()->create(['user_id' => $user->id])->id,
        ]);
    }

    #[Test]
    public function bulk_deleting_events_cannot_reach_another_users_event(): void
    {
        $victimEvent = $this->eventFor($this->victim);
        $ownEvent = $this->eventFor($this->admin);

        Volt::actingAs($this->admin)
            ->test('admin.events')
            ->set('selectedEvents', [$victimEvent->id, $ownEvent->id])
            ->call('bulkDelete');

        $this->assertNotSoftDeleted($victimEvent);
        $this->assertSoftDeleted($ownEvent);
    }

    #[Test]
    public function bulk_deleting_events_cannot_cascade_into_another_users_blocks(): void
    {
        $victimEvent = $this->eventFor($this->victim);
        $victimBlock = Block::factory()->create(['event_id' => $victimEvent->id]);

        Volt::actingAs($this->admin)
            ->test('admin.events')
            ->set('selectedEvents', [$victimEvent->id])
            ->call('bulkDelete');

        $this->assertNotSoftDeleted($victimBlock);
    }

    #[Test]
    public function bulk_deleting_objects_cannot_reach_another_users_object(): void
    {
        $victimObject = EventObject::factory()->create(['user_id' => $this->victim->id]);
        $ownObject = EventObject::factory()->create(['user_id' => $this->admin->id]);

        Volt::actingAs($this->admin)
            ->test('admin.objects')
            ->set('selectedObjects', [$victimObject->id, $ownObject->id])
            ->call('bulkDelete');

        $this->assertNotSoftDeleted($victimObject);
        $this->assertSoftDeleted($ownObject);
    }

    #[Test]
    public function bulk_deleting_blocks_cannot_reach_another_users_block(): void
    {
        $victimBlock = Block::factory()->create([
            'event_id' => $this->eventFor($this->victim)->id,
        ]);
        $ownBlock = Block::factory()->create([
            'event_id' => $this->eventFor($this->admin)->id,
        ]);

        Volt::actingAs($this->admin)
            ->test('admin.blocks')
            ->set('selectedBlocks', [$victimBlock->id, $ownBlock->id])
            ->call('bulkDelete');

        $this->assertNotSoftDeleted($victimBlock);
        $this->assertSoftDeleted($ownBlock);
    }

    private function relationshipFor(User $user): Relationship
    {
        $from = EventObject::factory()->create(['user_id' => $user->id]);
        $to = EventObject::factory()->create(['user_id' => $user->id]);

        return Relationship::create([
            'user_id' => $user->id,
            'from_type' => EventObject::class,
            'from_id' => $from->id,
            'to_type' => EventObject::class,
            'to_id' => $to->id,
            'type' => 'linked_to',
        ]);
    }

    #[Test]
    public function bulk_deleting_relationships_cannot_reach_another_users_relationship(): void
    {
        $victimRelationship = $this->relationshipFor($this->victim);
        $ownRelationship = $this->relationshipFor($this->admin);

        Volt::actingAs($this->admin)
            ->test('admin.relationships')
            ->set('selectedRelationships', [$victimRelationship->id, $ownRelationship->id])
            ->call('bulkDelete');

        $this->assertNotSoftDeleted($victimRelationship);
        $this->assertSoftDeleted($ownRelationship);
    }

    #[Test]
    public function the_bin_cannot_restore_another_users_deleted_record(): void
    {
        $victimObject = EventObject::factory()->create(['user_id' => $this->victim->id]);
        $victimObject->delete();

        Volt::actingAs($this->admin)
            ->test('admin.bin')
            ->set('selectedItems', [$victimObject->id])
            ->call('bulkRestore');

        $this->assertSoftDeleted($victimObject);
    }

    #[Test]
    public function the_bin_cannot_permanently_delete_another_users_record(): void
    {
        $victimObject = EventObject::factory()->create(['user_id' => $this->victim->id]);
        $victimObject->delete();

        Volt::actingAs($this->admin)
            ->test('admin.bin')
            ->set('selectedItems', [$victimObject->id])
            ->call('bulkDelete');

        $this->assertNotNull(
            EventObject::withTrashed()->find($victimObject->id),
            'Another tenant\'s soft-deleted record must survive an admin purge.',
        );
    }
}
