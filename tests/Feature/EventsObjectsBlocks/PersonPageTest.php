<?php

namespace Tests\Feature\EventsObjectsBlocks;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\PersonProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * EOB-D7: a person has their own page listing the events they appear in and
 * what they are connected to through relationships.
 */
class PersonPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->integration = $this->integrationFor($this->user);
    }

    #[Test]
    public function the_person_page_lists_their_events_and_connections(): void
    {
        $person = $this->person('Dan');
        Event::factory()->create(['integration_id' => $this->integration->id, 'service' => 'monzo', 'actor_id' => $person->id, 'action' => 'watched_show']);
        $cluster = EventObject::factory()->create(['user_id' => $this->user->id, 'concept' => 'photo_cluster', 'type' => 'immich_cluster', 'title' => 'Beach day']);
        $this->relate($person, $cluster, 'tagged_in');
        $friend = $this->person('Sam');
        $this->relate($friend, $person, 'related_to');

        $this->actingAs($this->user)
            ->get(route('people.show', $person->id))
            ->assertOk()
            ->assertSee('Dan')
            ->assertSee('Watched Show')
            ->assertSee('Beach day')
            ->assertSee('Sam')
            ->assertSee('1 event')
            ->assertSee('2 connections');

        $this->assertTrue(Activity::query()
            ->where('event', 'viewed')
            ->where('subject_type', EventObject::class)
            ->where('subject_id', $person->id)
            ->exists());
    }

    #[Test]
    public function an_object_that_is_not_a_person_has_no_person_page(): void
    {
        $account = EventObject::factory()->create(['user_id' => $this->user->id, 'concept' => 'account']);

        $this->actingAs($this->user)
            ->get(route('people.show', $account->id))
            ->assertNotFound();
    }

    #[Test]
    public function another_users_person_page_is_forbidden(): void
    {
        $person = $this->person('Dan');

        $this->actingAs(User::factory()->create())
            ->get(route('people.show', $person->id))
            ->assertForbidden();
    }

    #[Test]
    public function people_links_open_the_person_page(): void
    {
        $person = $this->person('Dan');

        $this->actingAs($this->user)
            ->get(route('objects.show', $person->id))
            ->assertOk()
            ->assertSee(route('people.show', $person->id), false);

        $html = $this->blade('<x-object-ref :object="$object" />', ['object' => $person]);
        $html->assertSee(route('people.show', $person->id), false);
        $html->assertDontSee(route('objects.show', $person->id), false);
    }

    #[Test]
    public function connections_leave_out_deleted_relationships_and_other_users_entities(): void
    {
        $person = $this->person('Dan');
        $kept = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Kept']);
        $this->relate($person, $kept, 'linked_to');
        $gone = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Gone']);
        $this->relate($person, $gone, 'linked_to')->delete();
        $deletedTarget = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Deleted target']);
        $this->relate($person, $deletedTarget, 'related_to');
        $deletedTarget->delete();
        $foreign = EventObject::factory()->create(['user_id' => User::factory()->create()->id, 'title' => 'Foreign']);
        $this->relate($person, $foreign, 'similar_to');

        $connections = app(PersonProfileService::class)->connections($person);

        $this->assertSame(['linked_to'], $connections->keys()->all());
        $this->assertSame(['Kept'], $connections['linked_to']->map(fn (array $connection) => $connection['related']->title)->all());
        $this->assertTrue($connections['linked_to']->first()['outgoing']);
    }

    #[Test]
    public function events_only_come_from_the_owners_integrations(): void
    {
        $person = $this->person('Dan');
        $own = Event::factory()->create(['integration_id' => $this->integration->id, 'service' => 'monzo', 'target_id' => $person->id]);
        Event::factory()->create(['integration_id' => $this->integrationFor(User::factory()->create())->id, 'service' => 'monzo', 'target_id' => $person->id]);

        $service = app(PersonProfileService::class);

        $this->assertSame([$own->id], $service->events($person)->pluck('id')->all());
        $this->assertSame(1, $service->eventCount($person));
    }

    private function person(string $name): EventObject
    {
        return EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'person',
            'type' => 'immich_person',
            'title' => $name,
            'metadata' => [],
        ]);
    }

    private function relate(EventObject $from, EventObject $to, string $type): Relationship
    {
        return Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $from->id,
            'to_type' => EventObject::class,
            'to_id' => $to->id,
            'type' => $type,
        ]);
    }

    private function integrationFor(User $user): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);

        return Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'monzo']);
    }
}
