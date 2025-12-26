<?php

namespace Tests\Feature\Integrations;

use App\Integrations\GoogleCalendar\GoogleCalendarPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GoogleCalendarLocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected GoogleCalendarPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'google-calendar',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'google-calendar',
            'configuration' => [
                'calendar_id' => 'primary',
                'calendar_name' => 'Primary',
            ],
        ]);

        $this->plugin = new GoogleCalendarPlugin;
    }

    /**
     * @test
     */
    public function physical_location_is_geocoded(): void
    {
        // Mock geocoding service
        $geocodingMock = Mockery::mock(GeocodingService::class);
        $geocodingMock->shouldReceive('geocode')
            ->once()
            ->with('123 Main St, London, UK')
            ->andReturn([
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'formatted_address' => '123 Main St, London, UK',
                'country_code' => 'GB',
                'source' => 'geoapify',
            ]);

        $this->app->instance(GeocodingService::class, $geocodingMock);

        $rawData = [
            'events' => [
                [
                    'id' => 'event_123',
                    'summary' => 'Team Meeting',
                    'description' => 'Monthly team sync',
                    'location' => '123 Main St, London, UK',
                    'status' => 'confirmed',
                    'start' => [
                        'dateTime' => now()->toIso8601String(),
                    ],
                    'end' => [
                        'dateTime' => now()->addHour()->toIso8601String(),
                    ],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary',
            'sync_window' => [],
        ];

        $this->plugin->processEventData($this->integration, $rawData);

        $events = Event::where('service', 'google_calendar')->get();

        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertNotNull($event->location);
        $this->assertEquals(51.5074, $event->latitude);
        $this->assertEquals(-0.1278, $event->longitude);
        $this->assertEquals('geoapify', $event->location_source);
    }

    /**
     * @test
     */
    public function virtual_location_with_zoom_is_skipped(): void
    {
        $rawData = [
            'events' => [
                [
                    'id' => 'event_456',
                    'summary' => 'Virtual Meeting',
                    'description' => 'Remote standup',
                    'location' => 'https://zoom.us/j/123456789',
                    'status' => 'confirmed',
                    'start' => [
                        'dateTime' => now()->toIso8601String(),
                    ],
                    'end' => [
                        'dateTime' => now()->addHour()->toIso8601String(),
                    ],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary',
            'sync_window' => [],
        ];

        $this->plugin->processEventData($this->integration, $rawData);

        $event = Event::where('service', 'google_calendar')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->location);
        $this->assertNull($event->location_address);
    }

    /**
     * @test
     */
    public function virtual_location_with_google_meet_is_skipped(): void
    {
        $rawData = [
            'events' => [
                [
                    'id' => 'event_789',
                    'summary' => 'Video Call',
                    'location' => 'https://meet.google.com/abc-defg-hij',
                    'status' => 'confirmed',
                    'start' => [
                        'dateTime' => now()->toIso8601String(),
                    ],
                    'end' => [
                        'dateTime' => now()->addHour()->toIso8601String(),
                    ],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary',
            'sync_window' => [],
        ];

        $this->plugin->processEventData($this->integration, $rawData);

        $event = Event::where('service', 'google_calendar')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->location);
    }

    /**
     * @test
     */
    public function event_without_location_is_handled(): void
    {
        $rawData = [
            'events' => [
                [
                    'id' => 'event_000',
                    'summary' => 'No Location Event',
                    'status' => 'confirmed',
                    'start' => [
                        'dateTime' => now()->toIso8601String(),
                    ],
                    'end' => [
                        'dateTime' => now()->addHour()->toIso8601String(),
                    ],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary',
            'sync_window' => [],
        ];

        $this->plugin->processEventData($this->integration, $rawData);

        $event = Event::where('service', 'google_calendar')->first();

        $this->assertNotNull($event);
        $this->assertNull($event->location);
    }
}
