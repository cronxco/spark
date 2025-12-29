<?php

namespace Tests\Unit\Models;

use App\Models\EventObject;
use App\Models\Person;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_extends_event_object()
    {
        $person = new Person;

        $this->assertInstanceOf(EventObject::class, $person);
    }

    /** @test */
    public function it_automatically_sets_concept_to_person_on_creation()
    {
        $person = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
        ]);

        $this->assertEquals('person', $person->concept);
    }

    /** @test */
    public function it_applies_global_scope_to_filter_by_concept()
    {
        // Create a person
        Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
        ]);

        // Create a non-person EventObject
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'photo_cluster',
            'type' => 'immich_cluster',
            'title' => 'Photos at 10am',
            'time' => now(),
        ]);

        // Person query should only return person concept
        $this->assertEquals(1, Person::count());
        $this->assertEquals(2, EventObject::withoutGlobalScope('people')->count());
    }

    /** @test */
    public function it_returns_photo_clusters_via_relationships()
    {
        $person = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
        ]);

        $cluster = EventObject::withoutGlobalScope('people')->create([
            'user_id' => $this->user->id,
            'concept' => 'photo_cluster',
            'type' => 'immich_cluster',
            'title' => 'Market Harborough at 10am',
            'time' => now(),
        ]);

        // Create tagged_in relationship (person FROM -> cluster TO)
        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $person->id,
            'to_type' => EventObject::class,
            'to_id' => $cluster->id,
            'type' => 'tagged_in',
        ]);

        $photoClusters = $person->photoClusters()->get();

        $this->assertCount(1, $photoClusters);
        $this->assertEquals($cluster->id, $photoClusters->first()->id);
    }

    /** @test */
    public function it_orders_photo_clusters_by_time_descending()
    {
        $person = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
        ]);

        $olderCluster = EventObject::withoutGlobalScope('people')->create([
            'user_id' => $this->user->id,
            'concept' => 'photo_cluster',
            'type' => 'immich_cluster',
            'title' => 'Older Photos',
            'time' => now()->subDays(5),
        ]);

        $newerCluster = EventObject::withoutGlobalScope('people')->create([
            'user_id' => $this->user->id,
            'concept' => 'photo_cluster',
            'type' => 'immich_cluster',
            'title' => 'Newer Photos',
            'time' => now()->subDays(1),
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $person->id,
            'to_type' => EventObject::class,
            'to_id' => $olderCluster->id,
            'type' => 'tagged_in',
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $person->id,
            'to_type' => EventObject::class,
            'to_id' => $newerCluster->id,
            'type' => 'tagged_in',
        ]);

        $photoClusters = $person->photoClusters()->get();

        $this->assertEquals($newerCluster->id, $photoClusters->first()->id);
        $this->assertEquals($olderCluster->id, $photoClusters->last()->id);
    }

    /** @test */
    public function it_filters_visible_people_excluding_hidden()
    {
        Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'Visible Person',
            'time' => now(),
            'metadata' => ['is_hidden' => false],
        ]);

        Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'Hidden Person',
            'time' => now(),
            'metadata' => ['is_hidden' => true],
        ]);

        Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'Person Without Flag',
            'time' => now(),
        ]);

        $visiblePeople = Person::visible()->get();

        $this->assertEquals(2, $visiblePeople->count());
        $this->assertFalse($visiblePeople->contains('title', 'Hidden Person'));
    }

    /** @test */
    public function it_orders_by_photo_count_descending()
    {
        $person1 = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'Person 1',
            'time' => now(),
            'metadata' => ['face_count' => 5],
        ]);

        $person2 = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'Person 2',
            'time' => now(),
            'metadata' => ['face_count' => 15],
        ]);

        $person3 = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'Person 3',
            'time' => now(),
            'metadata' => ['face_count' => 10],
        ]);

        $orderedPeople = Person::orderByPhotoCount()->get();

        $this->assertEquals($person2->id, $orderedPeople[0]->id);
        $this->assertEquals($person3->id, $orderedPeople[1]->id);
        $this->assertEquals($person1->id, $orderedPeople[2]->id);
    }

    /** @test */
    public function it_returns_photo_count_from_metadata()
    {
        $person = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
            'metadata' => ['face_count' => 42],
        ]);

        $this->assertEquals(42, $person->photo_count);
    }

    /** @test */
    public function it_returns_zero_photo_count_when_not_set()
    {
        $person = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
        ]);

        $this->assertEquals(0, $person->photo_count);
    }

    /** @test */
    public function it_uses_event_object_class_for_relationships_from()
    {
        $person = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
        ]);

        $cluster = EventObject::withoutGlobalScope('people')->create([
            'user_id' => $this->user->id,
            'concept' => 'photo_cluster',
            'type' => 'immich_cluster',
            'title' => 'Photos',
            'time' => now(),
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $person->id,
            'to_type' => EventObject::class,
            'to_id' => $cluster->id,
            'type' => 'tagged_in',
        ]);

        $this->assertCount(1, $person->relationshipsFrom);
    }

    /** @test */
    public function it_uses_event_object_class_for_relationships_to()
    {
        $person = Person::create([
            'user_id' => $this->user->id,
            'type' => 'immich_person',
            'title' => 'John Smith',
            'time' => now(),
        ]);

        $cluster = EventObject::withoutGlobalScope('people')->create([
            'user_id' => $this->user->id,
            'concept' => 'photo_cluster',
            'type' => 'immich_cluster',
            'title' => 'Photos',
            'time' => now(),
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $cluster->id,
            'to_type' => EventObject::class,
            'to_id' => $person->id,
            'type' => 'related_to',
        ]);

        $this->assertCount(1, $person->relationshipsTo);
    }
}
