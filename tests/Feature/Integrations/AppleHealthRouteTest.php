<?php

namespace Tests\Feature\Integrations;

use App\Integrations\AppleHealth\AppleHealthPlugin;
use App\Jobs\Data\AppleHealth\AppleHealthWorkoutData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use App\Services\GeocodingService;
use App\Services\PlaceDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AppleHealthRouteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected AppleHealthPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'apple_health',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'apple_health',
            'instance_type' => 'workouts',
            'name' => 'Apple Health Workouts',
        ]);

        $this->plugin = new AppleHealthPlugin;
    }

    /** @test */
    public function workout_with_route_data_extracts_gps_points(): void
    {
        $workoutData = [
            'id' => 'workout_123',
            'name' => 'Outdoor Run',
            'start' => now()->toIso8601String(),
            'duration' => 1800.0, // 30 minutes
            'distance' => ['qty' => 5.0, 'units' => 'km'],
            'location' => 'Outdoor',
            'route' => [
                [
                    'latitude' => 51.5074,
                    'longitude' => -0.1278,
                    'altitude' => 10.5,
                    'speed' => 2.5,
                    'timestamp' => now()->toIso8601String(),
                    'horizontalAccuracy' => 5.0,
                ],
                [
                    'latitude' => 51.5075,
                    'longitude' => -0.1279,
                    'altitude' => 11.0,
                    'speed' => 2.6,
                    'timestamp' => now()->addMinute()->toIso8601String(),
                    'horizontalAccuracy' => 5.0,
                ],
            ],
        ];

        $eventData = $this->plugin->mapWorkoutToEvent($workoutData, $this->integration);

        $this->assertArrayHasKey('route_points', $eventData['event_metadata']);
        $this->assertCount(2, $eventData['event_metadata']['route_points']);

        $firstPoint = $eventData['event_metadata']['route_points'][0];
        $this->assertEquals(51.5074, $firstPoint['lat']);
        $this->assertEquals(-0.1278, $firstPoint['lng']);
        $this->assertEquals(10.5, $firstPoint['alt']);
        $this->assertEquals(2.5, $firstPoint['speed']);
        $this->assertEquals(5.0, $firstPoint['accuracy']);
    }

    /** @test */
    public function workout_route_summary_is_calculated(): void
    {
        $workoutData = [
            'id' => 'workout_456',
            'name' => 'Morning Jog',
            'start' => now()->toIso8601String(),
            'duration' => 2400.0,
            'distance' => ['qty' => 3.5, 'units' => 'km'],
            'location' => 'Outdoor',
            'route' => [
                ['latitude' => 52.4862, 'longitude' => -1.8904],
                ['latitude' => 52.4863, 'longitude' => -1.8905],
                ['latitude' => 52.4864, 'longitude' => -1.8906],
            ],
        ];

        $eventData = $this->plugin->mapWorkoutToEvent($workoutData, $this->integration);

        $this->assertArrayHasKey('route_summary', $eventData['event_metadata']);
        $summary = $eventData['event_metadata']['route_summary'];

        $this->assertEquals(3, $summary['total_points']);
        $this->assertEquals(52.4862, $summary['start_location']['lat']);
        $this->assertEquals(-1.8904, $summary['start_location']['lng']);
        $this->assertEquals(52.4864, $summary['end_location']['lat']);
        $this->assertEquals(-1.8906, $summary['end_location']['lng']);
    }

    /** @test */
    public function outdoor_workout_with_route_sets_event_location(): void
    {
        // Mock geocoding service
        $geocodingMock = Mockery::mock(GeocodingService::class);
        $geocodingMock->shouldReceive('reverseGeocode')
            ->once()
            ->with(51.5074, -0.1278)
            ->andReturn([
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'formatted_address' => 'London, UK',
                'country_code' => 'GB',
                'source' => 'geoapify',
            ]);
        $this->app->instance(GeocodingService::class, $geocodingMock);

        // Mock place detection service
        $placeServiceMock = Mockery::mock(PlaceDetectionService::class);
        $placeServiceMock->shouldReceive('detectAndLinkPlaceForEvent')
            ->once();
        $this->app->instance(PlaceDetectionService::class, $placeServiceMock);

        $workoutData = [
            'id' => 'workout_789',
            'name' => 'Evening Walk',
            'start' => now()->toIso8601String(),
            'duration' => 1200.0,
            'distance' => ['qty' => 2.0, 'units' => 'km'],
            'location' => 'Outdoor',
            'route' => [
                ['latitude' => 51.5074, 'longitude' => -0.1278, 'altitude' => 15.0],
            ],
        ];

        // Process via job (which handles location setting)
        $job = new AppleHealthWorkoutData($this->integration, $workoutData);
        $job->handle();

        $event = Event::where('service', 'apple_health')->first();

        $this->assertNotNull($event);
        $this->assertNotNull($event->location);
        $this->assertEquals(51.5074, $event->latitude);
        $this->assertEquals(-0.1278, $event->longitude);
        $this->assertEquals('London, UK', $event->location_address);
        $this->assertEquals('apple_health_route', $event->location_source);
    }

    /** @test */
    public function indoor_workout_does_not_set_location(): void
    {
        $workoutData = [
            'id' => 'workout_indoor',
            'name' => 'Indoor Cycling',
            'start' => now()->toIso8601String(),
            'duration' => 3600.0,
            'distance' => ['qty' => 15.0, 'units' => 'km'],
            'location' => 'Indoor',
            // No route data
        ];

        $job = new AppleHealthWorkoutData($this->integration, $workoutData);
        $job->handle();

        $event = Event::where('service', 'apple_health')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->location);
        $this->assertNull($event->latitude);
        $this->assertNull($event->longitude);
    }

    /** @test */
    public function outdoor_workout_with_route_links_to_place(): void
    {
        // Create an existing place
        $existingPlace = Place::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Running Track',
            'concept' => 'place',
            'type' => 'discovered_place',
            'time' => now(),
        ]);
        $existingPlace->setLocation(51.5074, -0.1278, 'London, UK', 'manual');

        // Mock geocoding service
        $geocodingMock = Mockery::mock(GeocodingService::class);
        $geocodingMock->shouldReceive('reverseGeocode')
            ->once()
            ->andReturn([
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'formatted_address' => 'London, UK',
                'country_code' => 'GB',
                'source' => 'geoapify',
            ]);
        $this->app->instance(GeocodingService::class, $geocodingMock);

        // Use real PlaceDetectionService to test the full flow
        $workoutData = [
            'id' => 'workout_place',
            'name' => 'Track Run',
            'start' => now()->toIso8601String(),
            'duration' => 2400.0,
            'distance' => ['qty' => 5.0, 'units' => 'km'],
            'location' => 'Outdoor',
            'route' => [
                ['latitude' => 51.5074, 'longitude' => -0.1278],
            ],
        ];

        $job = new AppleHealthWorkoutData($this->integration, $workoutData);
        $job->handle();

        $event = Event::where('service', 'apple_health')->first();

        // Check that a relationship was created
        $relationship = Relationship::where('from_type', Event::class)
            ->where('from_id', $event->id)
            ->where('type', 'occurred_at')
            ->first();

        $this->assertNotNull($relationship);
    }

    /** @test */
    public function workout_without_route_data_works(): void
    {
        $workoutData = [
            'id' => 'workout_no_route',
            'name' => 'Gym Session',
            'start' => now()->toIso8601String(),
            'duration' => 3600.0,
            'activeEnergyBurned' => ['qty' => 450, 'units' => 'kcal'],
            'location' => 'Indoor',
            // No route data
        ];

        $eventData = $this->plugin->mapWorkoutToEvent($workoutData, $this->integration);

        $this->assertArrayHasKey('route_points', $eventData['event_metadata']);
        $this->assertEmpty($eventData['event_metadata']['route_points']);
        $this->assertEquals(0, $eventData['event_metadata']['route_summary']['total_points']);
    }

    /** @test */
    public function route_points_handle_missing_optional_fields(): void
    {
        $workoutData = [
            'id' => 'workout_minimal',
            'name' => 'Quick Walk',
            'start' => now()->toIso8601String(),
            'duration' => 600.0,
            'location' => 'Outdoor',
            'route' => [
                [
                    'latitude' => 51.5074,
                    'longitude' => -0.1278,
                    // Missing altitude, speed, timestamp, accuracy
                ],
            ],
        ];

        $eventData = $this->plugin->mapWorkoutToEvent($workoutData, $this->integration);

        $this->assertCount(1, $eventData['event_metadata']['route_points']);

        $point = $eventData['event_metadata']['route_points'][0];
        $this->assertEquals(51.5074, $point['lat']);
        $this->assertEquals(-0.1278, $point['lng']);
        $this->assertNull($point['alt']);
        $this->assertNull($point['speed']);
        $this->assertNull($point['timestamp']);
        $this->assertNull($point['accuracy']);
    }

    /** @test */
    public function location_tag_stored_in_metadata(): void
    {
        $workoutData = [
            'id' => 'workout_tag',
            'name' => 'Outdoor Run',
            'start' => now()->toIso8601String(),
            'duration' => 1800.0,
            'location' => 'Outdoor',
            'route' => [
                ['latitude' => 51.5074, 'longitude' => -0.1278],
            ],
        ];

        $eventData = $this->plugin->mapWorkoutToEvent($workoutData, $this->integration);

        $this->assertEquals('Outdoor', $eventData['event_metadata']['location']);

        // Should also have tag
        $this->assertContains([
            'name' => 'Outdoor',
            'type' => 'workout_location',
        ], $eventData['tags']);
    }

    /** @test */
    public function empty_route_array_is_handled(): void
    {
        $workoutData = [
            'id' => 'workout_empty_route',
            'name' => 'Treadmill Run',
            'start' => now()->toIso8601String(),
            'duration' => 1800.0,
            'location' => 'Indoor',
            'route' => [], // Empty array
        ];

        $eventData = $this->plugin->mapWorkoutToEvent($workoutData, $this->integration);

        $this->assertEmpty($eventData['event_metadata']['route_points']);
        $this->assertEquals(0, $eventData['event_metadata']['route_summary']['total_points']);
        $this->assertNull($eventData['event_metadata']['route_summary']['start_location']);
        $this->assertNull($eventData['event_metadata']['route_summary']['end_location']);
    }
}
