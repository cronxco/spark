<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use App\Services\GeocodingService;
use App\Services\PlaceDetectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PlaceDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PlaceDetectionService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Mock GeocodingService
        $geocodingMock = Mockery::mock(GeocodingService::class);
        $geocodingMock->shouldReceive('reverseGeocode')
            ->andReturn([
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'formatted_address' => 'Starbucks, 123 Main St, London, UK',
                'country_code' => 'GB',
                'source' => 'geoapify',
            ]);

        $this->service = new PlaceDetectionService($geocodingMock);
    }

    /**
     * @test
     */
    public function detects_existing_place_within_radius(): void
    {
        // Create existing place
        $existingPlace = Place::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Starbucks',
            'metadata' => ['visit_count' => 5],
        ]);
        $existingPlace->setLocation(51.5074, -0.1278, 'Starbucks, 123 Main St', 'manual');

        // Try to detect place at same location
        $place = $this->service->detectOrCreatePlace(
            51.5074,
            -0.1278,
            null,
            $this->user
        );

        // Should return existing place
        $this->assertEquals($existingPlace->id, $place->id);

        // Visit count should NOT increment when just detecting - only when linking an event
        $this->assertEquals(5, $place->visit_count);
    }

    /**
     * @test
     */
    public function creates_new_place_when_none_exists_nearby(): void
    {
        $this->assertEquals(0, Place::count());

        $place = $this->service->detectOrCreatePlace(
            51.5074,
            -0.1278,
            'Starbucks, 123 Main St, London',
            $this->user
        );

        $this->assertEquals(1, Place::count());
        $this->assertEquals($this->user->id, $place->user_id);
        $this->assertEquals('Starbucks', $place->title); // Extracted from address
        $this->assertEquals(51.5074, $place->latitude);
        $this->assertEquals(-0.1278, $place->longitude);
        $this->assertEquals(1, $place->visit_count);
    }

    /**
     * @test
     */
    public function extracts_meaningful_title_from_address(): void
    {
        $testCases = [
            'Starbucks, 123 Main St, London' => 'Starbucks',
            '123 Main St, London' => '123 Main St, London',
            'Home, London, UK' => 'Home',
        ];

        foreach ($testCases as $address => $expectedTitle) {
            Place::query()->delete(); // Clean up

            $place = $this->service->detectOrCreatePlace(
                51.5074,
                -0.1278,
                $address,
                $this->user
            );

            $this->assertEquals($expectedTitle, $place->title, "Failed for address: {$address}");
        }
    }

    /**
     * @test
     */
    public function guesses_category_from_address(): void
    {
        $testCases = [
            'Starbucks Coffee, Main St' => 'cafe',
            'Pure Gym, London' => 'gym',
            'Pizza Express Restaurant' => 'restaurant',
            'The Red Lion Pub' => 'bar',
            'Tesco Store' => 'shop',
        ];

        foreach ($testCases as $address => $expectedCategory) {
            Place::query()->delete();

            $place = $this->service->detectOrCreatePlace(
                51.5074,
                -0.1278,
                $address,
                $this->user
            );

            $this->assertEquals($expectedCategory, $place->category, "Failed for address: {$address}");
        }
    }

    /**
     * @test
     */
    public function links_event_to_place(): void
    {
        $place = Place::factory()->create(['user_id' => $this->user->id]);

        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $relationship = $this->service->linkEventToPlace($event, $place);

        $this->assertInstanceOf(Relationship::class, $relationship);
        $this->assertEquals('occurred_at', $relationship->type);
        $this->assertEquals($event->id, $relationship->from_id);
        $this->assertEquals($place->id, $relationship->to_id);
    }

    /**
     * @test
     */
    public function detects_and_links_place_for_event(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $event->setLocation(51.5074, -0.1278, 'Starbucks, Main St', 'manual');

        $this->assertEquals(0, Place::count());
        $this->assertEquals(0, Relationship::where('type', 'occurred_at')->count());

        $place = $this->service->detectAndLinkPlaceForEvent($event);

        $this->assertInstanceOf(Place::class, $place);
        $this->assertEquals(1, Place::count());
        $this->assertEquals(1, Relationship::where('type', 'occurred_at')->count());

        $relationship = Relationship::where('type', 'occurred_at')->first();
        $this->assertEquals($event->id, $relationship->from_id);
        $this->assertEquals($place->id, $relationship->to_id);
    }

    /**
     * @test
     */
    public function does_not_create_place_for_event_without_location(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $place = $this->service->detectAndLinkPlaceForEvent($event);

        $this->assertNull($place);
        $this->assertEquals(0, Place::count());
    }

    /**
     * @test
     */
    public function finds_nearby_place_within_custom_radius(): void
    {
        $existingPlace = Place::factory()->create(['user_id' => $this->user->id]);
        $existingPlace->setLocation(51.5074, -0.1278, 'Starbucks', 'manual');

        // Search within 100m radius - should find the place
        $place = $this->service->findNearbyPlace(
            51.5075, // Slightly different coordinates (about 11m away)
            -0.1279,
            $this->user,
            100
        );

        $this->assertNotNull($place);
        $this->assertEquals($existingPlace->id, $place->id);

        // Search within 5m radius - should NOT find the place
        $place = $this->service->findNearbyPlace(
            51.5075,
            -0.1279,
            $this->user,
            5
        );

        $this->assertNull($place);
    }

    /**
     * @test
     */
    public function merges_two_places_correctly(): void
    {
        $keepPlace = Place::factory()->create([
            'user_id' => $this->user->id,
            'metadata' => [
                'visit_count' => 10,
                'first_visit_at' => now()->subMonths(6)->toIso8601String(),
            ],
        ]);

        $removePlace = Place::factory()->create([
            'user_id' => $this->user->id,
            'metadata' => [
                'visit_count' => 5,
                'first_visit_at' => now()->subMonths(12)->toIso8601String(),
            ],
        ]);

        // Create events linked to removePlace
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $event1 = Event::factory()->create(['integration_id' => $integration->id]);
        $event2 = Event::factory()->create(['integration_id' => $integration->id]);

        Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => EventObject::class,
            'to_id' => $removePlace->id,
            'type' => 'occurred_at',
        ]);

        Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => Event::class,
            'from_id' => $event2->id,
            'to_type' => EventObject::class,
            'to_id' => $removePlace->id,
            'type' => 'occurred_at',
        ]);

        $mergedPlace = $this->service->mergePlaces($keepPlace, $removePlace);

        // Visit counts should be combined
        $this->assertEquals(15, $mergedPlace->visit_count);

        // Should keep earliest first_visit_at
        $this->assertEquals(
            now()->subMonths(12)->format('Y-m-d'),
            Carbon::parse($mergedPlace->metadata['first_visit_at'])->format('Y-m-d')
        );

        // Relationships should be moved to keepPlace
        $this->assertEquals(2, $keepPlace->relationshipsTo()->where('type', 'occurred_at')->count());

        // removePlace should be soft deleted
        $this->assertSoftDeleted($removePlace);
    }

    /**
     * @test
     */
    public function only_finds_places_for_correct_user(): void
    {
        $otherUser = User::factory()->create();

        $otherUserPlace = Place::factory()->create(['user_id' => $otherUser->id]);
        $otherUserPlace->setLocation(51.5074, -0.1278, 'Starbucks', 'manual');

        // Try to find place as different user
        $place = $this->service->findNearbyPlace(
            51.5074,
            -0.1278,
            $this->user, // Different user
            100
        );

        $this->assertNull($place);

        // Try to detect/create as original user - should create new place
        $newPlace = $this->service->detectOrCreatePlace(
            51.5074,
            -0.1278,
            'Starbucks',
            $this->user
        );

        $this->assertEquals(2, Place::count()); // Two places, one per user
        $this->assertEquals($this->user->id, $newPlace->user_id);
    }

    /**
     * @test
     */
    public function reprocessing_same_event_does_not_increment_visit_count(): void
    {
        $integration = Integration::factory()->create(['user_id' => $this->user->id]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'latitude' => 51.5074,
            'longitude' => -0.1278,
            'location_address' => 'Starbucks, 123 Main St',
        ]);

        // First processing - creates place with visit_count = 1
        $place = $this->service->detectAndLinkPlaceForEvent($event);

        $this->assertNotNull($place);
        $this->assertEquals(1, $place->visit_count);
        $this->assertEquals(1, Relationship::where('type', 'occurred_at')->count());

        // Reprocess same event (simulating re-sync) - should NOT increment visit count
        $place2 = $this->service->detectAndLinkPlaceForEvent($event);

        $this->assertEquals($place->id, $place2->id);
        $this->assertEquals(1, $place2->visit_count); // Still 1, not 2
        $this->assertEquals(1, Relationship::where('type', 'occurred_at')->count()); // Still only 1 relationship
    }
}
