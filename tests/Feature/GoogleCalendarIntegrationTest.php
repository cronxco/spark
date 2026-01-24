<?php

namespace Tests\Feature;

use App\Integrations\GoogleCalendar\GoogleCalendarPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class GoogleCalendarIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->group = IntegrationGroup::create([
            'user_id' => $this->user->id,
            'service' => 'google-calendar',
            'account_id' => 'test@example.com',
            'access_token' => 'test_access_token',
            'refresh_token' => 'test_refresh_token',
            'expiry' => now()->addHour(),
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'google-calendar',
            'name' => 'Work Calendar',
            'instance_type' => 'events',
            'configuration' => [
                'calendar_id' => 'primary',
                'calendar_name' => 'Primary Calendar',
                'update_frequency_minutes' => 15,
                'sync_days_past' => 7,
                'sync_days_future' => 30,
            ],
        ]);
    }

    #[Test]
    public function plugin_has_correct_metadata(): void
    {
        $this->assertEquals('google-calendar', GoogleCalendarPlugin::getIdentifier());
        $this->assertEquals('Google Calendar', GoogleCalendarPlugin::getDisplayName());
        $this->assertEquals('Sync events from your Google Calendar with filtering support.', GoogleCalendarPlugin::getDescription());
        $this->assertEquals('oauth', GoogleCalendarPlugin::getServiceType());
        $this->assertEquals('knowledge', GoogleCalendarPlugin::getDomain());
        $this->assertEquals('fab.google', GoogleCalendarPlugin::getIcon());
        $this->assertEquals('info', GoogleCalendarPlugin::getAccentColor());
    }

    #[Test]
    public function plugin_supports_migration(): void
    {
        $this->assertTrue(GoogleCalendarPlugin::supportsMigration());
    }

    #[Test]
    public function plugin_has_correct_action_types(): void
    {
        $actionTypes = GoogleCalendarPlugin::getActionTypes();

        $this->assertArrayHasKey('had_event', $actionTypes);
        $this->assertArrayHasKey('had_all_day_event', $actionTypes);

        $this->assertEquals('Had Event', $actionTypes['had_event']['display_name']);
        $this->assertEquals('minutes', $actionTypes['had_event']['value_unit']);

        $this->assertEquals('Had All-Day Event', $actionTypes['had_all_day_event']['display_name']);
        $this->assertEquals('minutes', $actionTypes['had_all_day_event']['value_unit']);
    }

    #[Test]
    public function plugin_has_correct_block_types(): void
    {
        $blockTypes = GoogleCalendarPlugin::getBlockTypes();

        $this->assertArrayHasKey('event_details', $blockTypes);
        $this->assertArrayHasKey('event_attendees', $blockTypes);
        $this->assertArrayHasKey('event_location', $blockTypes);

        $this->assertEquals('Event Details', $blockTypes['event_details']['display_name']);
        $this->assertEquals('Attendees', $blockTypes['event_attendees']['display_name']);
        $this->assertEquals('Location', $blockTypes['event_location']['display_name']);
    }

    #[Test]
    public function plugin_has_correct_object_types(): void
    {
        $objectTypes = GoogleCalendarPlugin::getObjectTypes();

        $this->assertArrayHasKey('google_calendar', $objectTypes);
        $this->assertArrayHasKey('calendar_event', $objectTypes);

        $this->assertEquals('Google Calendar', $objectTypes['google_calendar']['display_name']);
        $this->assertEquals('Calendar Event', $objectTypes['calendar_event']['display_name']);
    }

    #[Test]
    public function plugin_requires_full_calendar_scope(): void
    {
        $plugin = new GoogleCalendarPlugin;
        $reflection = new ReflectionClass($plugin);
        $method = $reflection->getMethod('getRequiredScopes');
        $scopes = $method->invoke($plugin);

        $this->assertEquals('https://www.googleapis.com/auth/calendar', $scopes);
    }

    #[Test]
    public function plugin_can_fetch_available_calendars(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/users/me/calendarList' => Http::response([
                'items' => [
                    [
                        'id' => 'primary',
                        'summary' => 'Primary Calendar',
                        'primary' => true,
                        'accessRole' => 'owner',
                    ],
                    [
                        'id' => 'work@example.com',
                        'summary' => 'Work Calendar',
                        'primary' => false,
                        'accessRole' => 'writer',
                    ],
                ],
            ], 200),
        ]);

        $plugin = new GoogleCalendarPlugin;
        $calendars = $plugin->fetchAvailableCalendars($this->group);

        $this->assertCount(2, $calendars);
        $this->assertEquals('primary', $calendars[0]['id']);
        $this->assertEquals('Primary Calendar', $calendars[0]['name']);
        $this->assertTrue($calendars[0]['primary']);
        $this->assertEquals('work@example.com', $calendars[1]['id']);
    }

    #[Test]
    public function pull_event_data_fetches_events_from_google_calendar(): void
    {
        $now = Carbon::now();

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [
                    [
                        'id' => 'event_1',
                        'summary' => 'Team Meeting',
                        'status' => 'confirmed',
                        'start' => [
                            'dateTime' => $now->copy()->addHours(1)->toIso8601String(),
                        ],
                        'end' => [
                            'dateTime' => $now->copy()->addHours(2)->toIso8601String(),
                        ],
                        'description' => 'Weekly team sync',
                        'location' => 'Conference Room A',
                        'attendees' => [
                            [
                                'email' => 'john@example.com',
                                'responseStatus' => 'accepted',
                            ],
                        ],
                    ],
                ],
                'nextSyncToken' => 'sync_token_123',
            ], 200),
        ]);

        $plugin = new GoogleCalendarPlugin;
        $data = $plugin->pullEventData($this->integration);

        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('calendar_id', $data);
        $this->assertCount(1, $data['events']);
        $this->assertEquals('event_1', $data['events'][0]['id']);
        $this->assertEquals('Team Meeting', $data['events'][0]['summary']);

        // Verify syncToken was stored
        $this->integration->refresh();
        $this->assertEquals('sync_token_123', $this->integration->configuration['sync_token']);
    }

    #[Test]
    public function pull_event_data_uses_sync_token_for_incremental_sync(): void
    {
        $this->integration->update([
            'configuration' => array_merge($this->integration->configuration, [
                'sync_token' => 'existing_sync_token',
            ]),
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [],
                'nextSyncToken' => 'new_sync_token',
            ], 200),
        ]);

        $plugin = new GoogleCalendarPlugin;
        $plugin->pullEventData($this->integration);

        // Verify syncToken parameter was sent
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'syncToken=existing_sync_token');
        });

        // Verify new syncToken was stored
        $this->integration->refresh();
        $this->assertEquals('new_sync_token', $this->integration->configuration['sync_token']);
    }

    #[Test]
    public function pull_event_data_handles_expired_sync_token(): void
    {
        $this->integration->update([
            'configuration' => array_merge($this->integration->configuration, [
                'sync_token' => 'expired_sync_token',
            ]),
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::sequence()
                ->push([], 410) // First call with syncToken fails with 410 Gone
                ->push([
                    'items' => [],
                    'nextSyncToken' => 'new_sync_token',
                ], 200), // Second call with full sync succeeds
        ]);

        $plugin = new GoogleCalendarPlugin;
        $plugin->pullEventData($this->integration);

        // Verify syncToken was cleared and new one stored
        $this->integration->refresh();
        $this->assertEquals('new_sync_token', $this->integration->configuration['sync_token']);
    }

    #[Test]
    public function process_event_data_creates_timed_event(): void
    {
        $now = Carbon::now();
        $rawData = [
            'events' => [
                [
                    'id' => 'event_1',
                    'summary' => 'Team Meeting',
                    'status' => 'confirmed',
                    'start' => [
                        'dateTime' => $now->copy()->addHours(1)->toIso8601String(),
                    ],
                    'end' => [
                        'dateTime' => $now->copy()->addHours(2)->toIso8601String(),
                    ],
                    'description' => 'Weekly team sync',
                    'location' => 'Conference Room A',
                    'htmlLink' => 'https://calendar.google.com/event?eid=event_1',
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary Calendar',
            'sync_window' => [
                'time_min' => $now->copy()->subDays(7)->toIso8601String(),
                'time_max' => $now->copy()->addDays(30)->toIso8601String(),
            ],
        ];

        $plugin = new GoogleCalendarPlugin;
        $plugin->processEventData($this->integration, $rawData);

        // Verify calendar EventObject was created
        $calendarObject = EventObject::where('user_id', $this->user->id)
            ->where('type', 'google_calendar')
            ->first();
        $this->assertNotNull($calendarObject);
        $this->assertEquals('Primary Calendar', $calendarObject->title);

        // Verify event EventObject was created
        $eventObject = EventObject::where('user_id', $this->user->id)
            ->where('type', 'calendar_event')
            ->first();
        $this->assertNotNull($eventObject);
        $this->assertEquals('Team Meeting', $eventObject->title);

        // Verify Event was created
        $event = Event::where('integration_id', $this->integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('had_event', $event->action);
        $this->assertEquals(60, $event->value); // 1 hour = 60 minutes
        $this->assertEquals($calendarObject->id, $event->actor_id);
        $this->assertEquals($eventObject->id, $event->target_id);

        // Verify metadata
        $this->assertEquals('event_1', $event->event_metadata['google_event_id']);
        $this->assertEquals('Weekly team sync', $event->event_metadata['description']);
        $this->assertEquals('Conference Room A', $event->event_metadata['location']);

        // Verify blocks were created
        $blocks = $event->blocks;
        $this->assertGreaterThan(0, $blocks->count());

        $detailsBlock = $blocks->where('block_type', 'event_details')->first();
        $this->assertNotNull($detailsBlock);
        $this->assertEquals('Weekly team sync', $detailsBlock->metadata['description']);

        $locationBlock = $blocks->where('block_type', 'event_location')->first();
        $this->assertNotNull($locationBlock);
        $this->assertEquals('Conference Room A', $locationBlock->metadata['location']);
    }

    #[Test]
    public function process_event_data_creates_all_day_event(): void
    {
        $now = Carbon::now();
        $rawData = [
            'events' => [
                [
                    'id' => 'event_2',
                    'summary' => 'Company Holiday',
                    'status' => 'confirmed',
                    'start' => [
                        'date' => $now->toDateString(),
                    ],
                    'end' => [
                        'date' => $now->copy()->addDay()->toDateString(),
                    ],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary Calendar',
            'sync_window' => [
                'time_min' => $now->copy()->subDays(7)->toIso8601String(),
                'time_max' => $now->copy()->addDays(30)->toIso8601String(),
            ],
        ];

        $plugin = new GoogleCalendarPlugin;
        $plugin->processEventData($this->integration, $rawData);

        // Verify Event was created as all-day event
        $event = Event::where('integration_id', $this->integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('had_all_day_event', $event->action);
    }

    #[Test]
    public function process_event_data_filters_events_by_exclude_pattern(): void
    {
        $this->integration->update([
            'configuration' => array_merge($this->integration->configuration, [
                'title_exclude_patterns' => ['/\[CANCELLED\]/'],
            ]),
        ]);

        $now = Carbon::now();
        $rawData = [
            'events' => [
                [
                    'id' => 'event_1',
                    'summary' => 'Team Meeting',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => $now->copy()->addHours(1)->toIso8601String()],
                    'end' => ['dateTime' => $now->copy()->addHours(2)->toIso8601String()],
                ],
                [
                    'id' => 'event_2',
                    'summary' => '[CANCELLED] Workshop',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => $now->copy()->addHours(3)->toIso8601String()],
                    'end' => ['dateTime' => $now->copy()->addHours(4)->toIso8601String()],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary Calendar',
            'sync_window' => [
                'time_min' => $now->copy()->subDays(7)->toIso8601String(),
                'time_max' => $now->copy()->addDays(30)->toIso8601String(),
            ],
        ];

        $plugin = new GoogleCalendarPlugin;
        $plugin->processEventData($this->integration, $rawData);

        // Verify only the non-cancelled event was created
        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertCount(1, $events);

        $eventObject = EventObject::where('user_id', $this->user->id)
            ->where('type', 'calendar_event')
            ->first();
        $this->assertEquals('Team Meeting', $eventObject->title);
    }

    #[Test]
    public function process_event_data_filters_events_by_include_pattern(): void
    {
        $this->integration->update([
            'configuration' => array_merge($this->integration->configuration, [
                'title_include_patterns' => ['/\[WORK\]/'],
            ]),
        ]);

        $now = Carbon::now();
        $rawData = [
            'events' => [
                [
                    'id' => 'event_1',
                    'summary' => '[WORK] Team Meeting',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => $now->copy()->addHours(1)->toIso8601String()],
                    'end' => ['dateTime' => $now->copy()->addHours(2)->toIso8601String()],
                ],
                [
                    'id' => 'event_2',
                    'summary' => 'Personal Appointment',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => $now->copy()->addHours(3)->toIso8601String()],
                    'end' => ['dateTime' => $now->copy()->addHours(4)->toIso8601String()],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary Calendar',
            'sync_window' => [
                'time_min' => $now->copy()->subDays(7)->toIso8601String(),
                'time_max' => $now->copy()->addDays(30)->toIso8601String(),
            ],
        ];

        $plugin = new GoogleCalendarPlugin;
        $plugin->processEventData($this->integration, $rawData);

        // Verify only the work event was created
        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertCount(1, $events);

        $eventObject = EventObject::where('user_id', $this->user->id)
            ->where('type', 'calendar_event')
            ->first();
        $this->assertEquals('[WORK] Team Meeting', $eventObject->title);
    }

    #[Test]
    public function process_event_data_skips_cancelled_events(): void
    {
        $now = Carbon::now();
        $rawData = [
            'events' => [
                [
                    'id' => 'event_1',
                    'summary' => 'Team Meeting',
                    'status' => 'cancelled',
                    'start' => ['dateTime' => $now->copy()->addHours(1)->toIso8601String()],
                    'end' => ['dateTime' => $now->copy()->addHours(2)->toIso8601String()],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary Calendar',
            'sync_window' => [
                'time_min' => $now->copy()->subDays(7)->toIso8601String(),
                'time_max' => $now->copy()->addDays(30)->toIso8601String(),
            ],
        ];

        $plugin = new GoogleCalendarPlugin;
        $plugin->processEventData($this->integration, $rawData);

        // Verify no events were created
        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertCount(0, $events);
    }

    #[Test]
    public function process_event_data_creates_attendees_block(): void
    {
        $now = Carbon::now();
        $rawData = [
            'events' => [
                [
                    'id' => 'event_1',
                    'summary' => 'Team Meeting',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => $now->copy()->addHours(1)->toIso8601String()],
                    'end' => ['dateTime' => $now->copy()->addHours(2)->toIso8601String()],
                    'attendees' => [
                        [
                            'email' => 'john@example.com',
                            'displayName' => 'John Doe',
                            'responseStatus' => 'accepted',
                        ],
                        [
                            'email' => 'jane@example.com',
                            'displayName' => 'Jane Smith',
                            'responseStatus' => 'tentative',
                        ],
                    ],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary Calendar',
            'sync_window' => [
                'time_min' => $now->copy()->subDays(7)->toIso8601String(),
                'time_max' => $now->copy()->addDays(30)->toIso8601String(),
            ],
        ];

        $plugin = new GoogleCalendarPlugin;
        $plugin->processEventData($this->integration, $rawData);

        $event = Event::where('integration_id', $this->integration->id)->first();
        $attendeesBlock = $event->blocks->where('block_type', 'event_attendees')->first();

        $this->assertNotNull($attendeesBlock);
        $this->assertCount(2, $attendeesBlock->metadata['attendees']);
        $this->assertEquals('john@example.com', $attendeesBlock->metadata['attendees'][0]['email']);
        $this->assertEquals('accepted', $attendeesBlock->metadata['attendees'][0]['responseStatus']);
    }

    #[Test]
    public function process_event_data_handles_event_deletion(): void
    {
        $now = Carbon::now();

        // Create an existing event that should be deleted
        $calendarObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'calendar',
            'type' => 'google_calendar',
            'title' => 'Primary Calendar',
        ]);

        $eventObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'event',
            'type' => 'calendar_event',
            'title' => 'Old Event',
        ]);

        $oldEvent = Event::create([
            'integration_id' => $this->integration->id,
            'source_id' => 'google_calendar_primary_old_event_'.$now->timestamp,
            'actor_id' => $calendarObject->id,
            'target_id' => $eventObject->id,
            'service' => 'google_calendar',
            'domain' => 'health',
            'action' => 'had_event',
            'time' => $now,
            'value' => 60,
            'event_metadata' => ['google_event_id' => 'old_event'],
        ]);

        // Process new data without the old event
        $rawData = [
            'events' => [
                [
                    'id' => 'new_event',
                    'summary' => 'New Event',
                    'status' => 'confirmed',
                    'start' => ['dateTime' => $now->copy()->addHours(1)->toIso8601String()],
                    'end' => ['dateTime' => $now->copy()->addHours(2)->toIso8601String()],
                ],
            ],
            'calendar_id' => 'primary',
            'calendar_name' => 'Primary Calendar',
            'sync_window' => [
                'time_min' => $now->copy()->subDays(7)->toIso8601String(),
                'time_max' => $now->copy()->addDays(30)->toIso8601String(),
            ],
        ];

        $plugin = new GoogleCalendarPlugin;
        $plugin->processEventData($this->integration, $rawData);

        // Verify old event was deleted (soft delete)
        $this->assertSoftDeleted('events', ['id' => $oldEvent->id]);

        // Verify new event was created
        $newEvent = Event::where('integration_id', $this->integration->id)
            ->where('source_id', 'like', '%new_event%')
            ->first();
        $this->assertNotNull($newEvent);
    }

    #[Test]
    public function token_is_refreshed_when_expired(): void
    {
        config([
            'services.google-calendar.client_id' => 'test-client-id',
            'services.google-calendar.client_secret' => 'test-client-secret',
        ]);

        $this->group->update([
            'expiry' => now()->subHour(), // Expired token
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new_access_token',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [],
                'nextSyncToken' => 'sync_token_123',
            ], 200),
        ]);

        $plugin = new GoogleCalendarPlugin;
        $plugin->pullEventData($this->integration);

        // Verify token was refreshed
        $this->group->refresh();
        $this->assertEquals('new_access_token', $this->group->access_token);
        $this->assertTrue($this->group->expiry->isFuture());

        // Verify token refresh endpoint was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'oauth2.googleapis.com/token') &&
                $request['grant_type'] === 'refresh_token';
        });
    }

    #[Test]
    public function fetch_data_method_calls_pull_and_process(): void
    {
        $now = Carbon::now();

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [
                    [
                        'id' => 'event_1',
                        'summary' => 'Team Meeting',
                        'status' => 'confirmed',
                        'start' => ['dateTime' => $now->copy()->addHours(1)->toIso8601String()],
                        'end' => ['dateTime' => $now->copy()->addHours(2)->toIso8601String()],
                    ],
                ],
                'nextSyncToken' => 'sync_token_123',
            ], 200),
        ]);

        $plugin = new GoogleCalendarPlugin;
        $plugin->fetchData($this->integration);

        // Verify event was created (meaning both pull and process were called)
        $event = Event::where('integration_id', $this->integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('had_event', $event->action);
    }

    #[Test]
    public function pull_event_data_handles_string_sync_days_configuration(): void
    {
        // Test that string values for sync_days_past and sync_days_future are properly cast to integers
        $this->integration->update([
            'configuration' => [
                'calendar_id' => 'primary',
                'calendar_name' => 'Primary Calendar',
                'update_frequency_minutes' => 15,
                'sync_days_past' => '14',  // String instead of int
                'sync_days_future' => '60',  // String instead of int
            ],
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [],
                'nextSyncToken' => 'sync_token_123',
            ], 200),
        ]);

        $plugin = new GoogleCalendarPlugin;
        $data = $plugin->pullEventData($this->integration);

        // The method should not throw a Carbon type error
        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('sync_window', $data);

        // Verify the correct time windows were calculated
        Http::assertSent(function ($request) {
            $query = $request->url();

            // Extract timeMin and timeMax from the request
            return str_contains($query, 'timeMin') && str_contains($query, 'timeMax');
        });
    }
}
