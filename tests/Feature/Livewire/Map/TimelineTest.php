<?php

namespace Tests\Feature\Livewire\Map;

use App\Livewire\Map\Index;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test_service',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'test_service',
        ]);
    }

    /** @test */
    public function timeline_data_groups_events_by_day(): void
    {
        // Create events on different days
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 14:00:00'),
            'service' => 'test_service',
        ]);
        $event2->setLocation(51.5075, -0.1279, 'London, UK', 'test');

        $event3 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-26 10:00:00'),
            'service' => 'test_service',
        ]);
        $event3->setLocation(51.5076, -0.1280, 'London, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('timelineGrouping', 'day');
        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-26');

        $timelineData = $component->get('timelineData');

        $this->assertCount(2, $timelineData); // 2 days
        $this->assertArrayHasKey('2025-12-25', $timelineData);
        $this->assertArrayHasKey('2025-12-26', $timelineData);
        $this->assertCount(2, $timelineData['2025-12-25']); // 2 events on Dec 25
        $this->assertCount(1, $timelineData['2025-12-26']); // 1 event on Dec 26
    }

    /** @test */
    public function timeline_data_groups_events_by_hour(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:15:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:45:00'),
            'service' => 'test_service',
        ]);
        $event2->setLocation(51.5075, -0.1279, 'London, UK', 'test');

        $event3 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 11:15:00'),
            'service' => 'test_service',
        ]);
        $event3->setLocation(51.5076, -0.1280, 'London, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('timelineGrouping', 'hour');
        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-25');

        $timelineData = $component->get('timelineData');

        $this->assertCount(2, $timelineData); // 2 hours
        $this->assertArrayHasKey('2025-12-25 10:00', $timelineData);
        $this->assertArrayHasKey('2025-12-25 11:00', $timelineData);
        $this->assertCount(2, $timelineData['2025-12-25 10:00']);
        $this->assertCount(1, $timelineData['2025-12-25 11:00']);
    }

    /** @test */
    public function timeline_data_groups_events_by_week(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        // Dec 23, 2025 is a Monday (start of week)
        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-23 10:00:00'), // Monday
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'), // Wednesday
            'service' => 'test_service',
        ]);
        $event2->setLocation(51.5075, -0.1279, 'London, UK', 'test');

        $event3 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-30 10:00:00'), // Next Monday
            'service' => 'test_service',
        ]);
        $event3->setLocation(51.5076, -0.1280, 'London, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('timelineGrouping', 'week');
        $component->set('startDate', '2025-12-20');
        $component->set('endDate', '2025-12-31');

        $timelineData = $component->get('timelineData');

        $this->assertCount(2, $timelineData); // 2 weeks
    }

    /** @test */
    public function journey_routes_calculates_polylines_between_events(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 11:00:00'),
            'service' => 'test_service',
        ]);
        $event2->setLocation(52.4862, -1.8904, 'Birmingham, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('showJourneyRoutes', true);
        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-25');

        $routes = $component->get('journeyRoutes');

        $this->assertCount(1, $routes);
        $this->assertEquals(51.5074, $routes[0]['from']['lat']);
        $this->assertEquals(-0.1278, $routes[0]['from']['lng']);
        $this->assertEquals(52.4862, $routes[0]['to']['lat']);
        $this->assertEquals(-1.8904, $routes[0]['to']['lng']);
        $this->assertEquals(60, $routes[0]['time_gap_minutes']);
    }

    /** @test */
    public function journey_routes_respects_toggle(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 11:00:00'),
            'service' => 'test_service',
        ]);
        $event2->setLocation(52.4862, -1.8904, 'Birmingham, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('showJourneyRoutes', false);
        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-25');

        $routes = $component->get('journeyRoutes');

        $this->assertEmpty($routes);
    }

    /** @test */
    public function timeline_applies_service_filter(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 11:00:00'),
            'service' => 'other_service',
        ]);
        $event2->setLocation(52.4862, -1.8904, 'Birmingham, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('selectedServices', ['test_service']);
        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-25');

        $timelineData = $component->get('timelineData');

        $this->assertCount(1, $timelineData);
        $allEvents = $timelineData->flatten(1);
        $this->assertCount(1, $allEvents);
        $this->assertEquals('test_service', $allEvents[0]->service);
    }

    /** @test */
    public function timeline_applies_date_range_filter(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-24 10:00:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'),
            'service' => 'test_service',
        ]);
        $event2->setLocation(52.4862, -1.8904, 'Birmingham, UK', 'test');

        $event3 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-26 10:00:00'),
            'service' => 'test_service',
        ]);
        $event3->setLocation(53.4084, -2.9916, 'Liverpool, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-25');

        $timelineData = $component->get('timelineData');

        $allEvents = $timelineData->flatten(1);
        $this->assertCount(1, $allEvents);
        $this->assertEquals('2025-12-25', $allEvents[0]->time->format('Y-m-d'));
    }

    /** @test */
    public function timeline_shows_only_events_with_location(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 11:00:00'),
            'service' => 'test_service',
            // No location
        ]);

        $component = Livewire::test(Index::class);

        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-25');

        $timelineData = $component->get('timelineData');

        $allEvents = $timelineData->flatten(1);
        $this->assertCount(1, $allEvents);
        $this->assertNotNull($allEvents[0]->location);
    }

    /** @test */
    public function timeline_sorts_events_chronologically(): void
    {
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event1 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 14:00:00'),
            'service' => 'test_service',
        ]);
        $event1->setLocation(51.5074, -0.1278, 'London, UK', 'test');

        $event2 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 10:00:00'),
            'service' => 'test_service',
        ]);
        $event2->setLocation(52.4862, -1.8904, 'Birmingham, UK', 'test');

        $event3 = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'target_id' => $target->id,
            'time' => Carbon::parse('2025-12-25 12:00:00'),
            'service' => 'test_service',
        ]);
        $event3->setLocation(53.4084, -2.9916, 'Liverpool, UK', 'test');

        $component = Livewire::test(Index::class);

        $component->set('startDate', '2025-12-25');
        $component->set('endDate', '2025-12-25');

        $timelineData = $component->get('timelineData');

        $allEvents = $timelineData->flatten(1);
        $this->assertCount(3, $allEvents);
        $this->assertEquals('10:00', $allEvents[0]->time->format('H:i'));
        $this->assertEquals('12:00', $allEvents[1]->time->format('H:i'));
        $this->assertEquals('14:00', $allEvents[2]->time->format('H:i'));
    }

    /** @test */
    public function changing_timeline_grouping_dispatches_event(): void
    {
        $component = Livewire::test(Index::class);

        $component->set('timelineGrouping', 'hour')
            ->assertDispatched('map-filters-updated');
    }

    /** @test */
    public function toggling_journey_routes_dispatches_event(): void
    {
        $component = Livewire::test(Index::class);

        $component->set('showJourneyRoutes', false)
            ->assertDispatched('map-filters-updated');
    }
}
