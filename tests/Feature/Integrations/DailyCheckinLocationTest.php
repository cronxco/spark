<?php

namespace Tests\Feature\Integrations;

use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use App\Services\GeocodingService;
use App\Services\PlaceDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class DailyCheckinLocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected DailyCheckinPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'daily_checkin',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'daily_checkin',
            'instance_type' => 'checkin',
            'name' => 'Daily Check-in',
        ]);

        $this->plugin = new DailyCheckinPlugin;
    }

    /** @test */
    public function checkin_without_location_works(): void
    {
        $event = $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            4,
            5,
            now()->format('Y-m-d')
        );

        $this->assertInstanceOf(Event::class, $event);
        $this->assertEquals(9, $event->value); // 4 + 5
        $this->assertEquals('had_morning_checkin', $event->action);
        $this->assertNull($event->location);
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);
        $this->assertEquals(false, $event->event_metadata['has_location'] ?? false);
    }

    /** @test */
    public function checkin_with_location_sets_coordinates(): void
    {
        // Mock geocoding service (shouldn't be called since we're providing address)
        $geocodingMock = Mockery::mock(GeocodingService::class);
        $this->app->instance(GeocodingService::class, $geocodingMock);

        $event = $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            3,
            4,
            now()->format('Y-m-d'),
            51.5074,
            -0.1278,
            'London, UK'
        );

        $this->assertNotNull($event->location);
        $this->assertEquals(51.5074, $event->latitude);
        $this->assertEquals(-0.1278, $event->longitude);
        $this->assertEquals('London, UK', $event->location_address);
        $this->assertEquals('daily_checkin', $event->location_source);
        $this->assertEquals(true, $event->event_metadata['has_location']);
    }

    /** @test */
    public function checkin_with_location_links_to_existing_place(): void
    {
        // Create an existing place at the same location
        $existingPlace = Place::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Home',
            'concept' => 'place',
            'type' => 'discovered_place',
            'time' => now(),
            'metadata' => [
                'visit_count' => 5,
                'category' => 'home',
            ],
        ]);
        $existingPlace->setLocation(51.5074, -0.1278, 'London, UK', 'manual');

        // Mock PlaceDetectionService to find the existing place
        $placeServiceMock = Mockery::mock(PlaceDetectionService::class);
        $placeServiceMock->shouldReceive('detectAndLinkPlaceForEvent')
            ->once()
            ->andReturnUsing(function ($event) use ($existingPlace) {
                Relationship::createRelationship([
                    'user_id' => $event->integration->user_id,
                    'from_type' => Event::class,
                    'from_id' => $event->id,
                    'to_type' => get_class($existingPlace),
                    'to_id' => $existingPlace->id,
                    'type' => 'occurred_at',
                ]);
            });
        $this->app->instance(PlaceDetectionService::class, $placeServiceMock);

        $event = $this->plugin->createCheckinEvent(
            $this->integration,
            'afternoon',
            5,
            5,
            now()->format('Y-m-d'),
            51.5074,
            -0.1278,
            'London, UK'
        );

        // Verify the event is linked to the place via relationship
        $relationship = Relationship::where('from_type', Event::class)
            ->where('from_id', $event->id)
            ->where('to_type', get_class($existingPlace))
            ->where('to_id', $existingPlace->id)
            ->where('type', 'occurred_at')
            ->first();

        $this->assertNotNull($relationship);
    }

    /** @test */
    public function afternoon_checkin_with_location_works(): void
    {
        $event = $this->plugin->createCheckinEvent(
            $this->integration,
            'afternoon',
            3,
            3,
            now()->format('Y-m-d'),
            52.4862,
            -1.8904,
            'Birmingham, UK'
        );

        $this->assertEquals('had_afternoon_checkin', $event->action);
        $this->assertEquals(6, $event->value);
        $this->assertNotNull($event->location);
        $this->assertEquals(52.4862, $event->latitude);
        $this->assertEquals(-1.8904, $event->longitude);
        $this->assertEquals('Birmingham, UK', $event->location_address);
    }

    /** @test */
    public function checkin_stores_physical_and_mental_energy_separately(): void
    {
        $event = $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            2,
            5,
            now()->format('Y-m-d'),
            51.5074,
            -0.1278,
            'London, UK'
        );

        $this->assertEquals(2, $event->event_metadata['physical_energy']);
        $this->assertEquals(5, $event->event_metadata['mental_energy']);
        $this->assertEquals(7, $event->value); // Combined

        // Verify blocks are created
        $blocks = $event->blocks;
        $this->assertCount(2, $blocks);

        $physicalBlock = $blocks->where('block_type', 'physical_energy')->first();
        $this->assertNotNull($physicalBlock);
        $this->assertEquals(2, $physicalBlock->value);

        $mentalBlock = $blocks->where('block_type', 'mental_energy')->first();
        $this->assertNotNull($mentalBlock);
        $this->assertEquals(5, $mentalBlock->value);
    }

    /** @test */
    public function checkin_validates_period(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Period must be either "morning" or "afternoon"');

        $this->plugin->createCheckinEvent(
            $this->integration,
            'evening',
            3,
            4,
            now()->format('Y-m-d')
        );
    }

    /** @test */
    public function checkin_validates_energy_ratings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Energy ratings must be between 1 and 5');

        $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            6, // Invalid: >5
            4,
            now()->format('Y-m-d')
        );
    }

    /** @test */
    public function checkin_can_be_updated_with_same_source_id(): void
    {
        $date = now()->format('Y-m-d');

        // Create initial check-in
        $event1 = $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            3,
            3,
            $date
        );

        // Update the same check-in
        $event2 = $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            5,
            4,
            $date,
            51.5074,
            -0.1278,
            'London, UK'
        );

        // Should be the same event (updateOrCreate)
        $this->assertEquals($event1->id, $event2->id);
        $this->assertEquals(9, $event2->value); // Updated to 5+4
        $this->assertNotNull($event2->location); // Now has location
    }

    /** @test */
    public function multiple_checkins_on_same_day_are_separate(): void
    {
        $date = now()->format('Y-m-d');

        $morningEvent = $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            4,
            5,
            $date
        );

        $afternoonEvent = $this->plugin->createCheckinEvent(
            $this->integration,
            'afternoon',
            3,
            4,
            $date
        );

        $this->assertNotEquals($morningEvent->id, $afternoonEvent->id);
        $this->assertEquals('had_morning_checkin', $morningEvent->action);
        $this->assertEquals('had_afternoon_checkin', $afternoonEvent->action);
    }

    /** @test */
    public function get_checkins_for_date_returns_both_periods(): void
    {
        $date = now()->format('Y-m-d');

        $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            4,
            5,
            $date
        );

        $this->plugin->createCheckinEvent(
            $this->integration,
            'afternoon',
            3,
            4,
            $date
        );

        $checkins = $this->plugin->getCheckinsForDate($this->user->id, $date);

        $this->assertArrayHasKey('morning', $checkins);
        $this->assertArrayHasKey('afternoon', $checkins);
        $this->assertNotNull($checkins['morning']);
        $this->assertNotNull($checkins['afternoon']);
        $this->assertEquals(9, $checkins['morning']->value);
        $this->assertEquals(7, $checkins['afternoon']->value);
    }

    /** @test */
    public function get_checkins_for_date_returns_null_for_missing_periods(): void
    {
        $date = now()->format('Y-m-d');

        // Only create morning check-in
        $this->plugin->createCheckinEvent(
            $this->integration,
            'morning',
            4,
            5,
            $date
        );

        $checkins = $this->plugin->getCheckinsForDate($this->user->id, $date);

        $this->assertNotNull($checkins['morning']);
        $this->assertNull($checkins['afternoon']);
    }
}
