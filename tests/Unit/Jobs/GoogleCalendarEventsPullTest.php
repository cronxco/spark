<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\GoogleCalendar\GoogleCalendarEventsData;
use App\Jobs\OAuth\GoogleCalendar\GoogleCalendarEventsPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class GoogleCalendarEventsPullTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'google-calendar',
            'account_id' => 'test@example.com',
            'access_token' => 'test_access_token',
            'expiry' => now()->addHour(),
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'google-calendar',
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
    public function job_creation(): void
    {
        $job = new GoogleCalendarEventsPull($this->integration);

        $this->assertInstanceOf(GoogleCalendarEventsPull::class, $job);
        $this->assertEquals(120, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertIsArray($job->backoff);
    }

    #[Test]
    public function unique_id_generation(): void
    {
        $job = new GoogleCalendarEventsPull($this->integration);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('google-calendar_events_'.$this->integration->id, $uniqueId);
        $this->assertStringContainsString(date('Y-m-d'), $uniqueId);
    }

    #[Test]
    public function get_service_name(): void
    {
        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('getServiceName');

        $this->assertEquals('google-calendar', $method->invoke($job));
    }

    #[Test]
    public function get_job_type(): void
    {
        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('getJobType');

        $this->assertEquals('events', $method->invoke($job));
    }

    #[Test]
    public function fetch_data_returns_events(): void
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
                    ],
                ],
                'nextSyncToken' => 'sync_token_123',
            ], 200),
        ]);

        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('fetchData');

        $result = $method->invoke($job);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('events', $result);
        $this->assertCount(1, $result['events']);
        $this->assertEquals('event_1', $result['events'][0]['id']);
    }

    #[Test]
    public function dispatch_processing_jobs_when_events_exist(): void
    {
        Queue::fake();

        $rawData = [
            'events' => [
                [
                    'id' => 'event_1',
                    'summary' => 'Team Meeting',
                ],
            ],
            'calendar_id' => 'primary',
        ];

        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('dispatchProcessingJobs');

        $method->invoke($job, $rawData);

        Queue::assertPushed(GoogleCalendarEventsData::class, function ($pushedJob) {
            return $pushedJob->getIntegration()->id === $this->integration->id;
        });
    }

    #[Test]
    public function does_not_dispatch_processing_jobs_when_no_events(): void
    {
        Queue::fake();

        $rawData = [
            'events' => [],
            'calendar_id' => 'primary',
        ];

        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('dispatchProcessingJobs');

        $method->invoke($job, $rawData);

        Queue::assertNotPushed(GoogleCalendarEventsData::class);
    }

    #[Test]
    public function job_handles_api_errors_gracefully(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'Forbidden',
                ],
            ], 403),
        ]);

        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('fetchData');

        $result = $method->invoke($job);

        // Should return empty array on error
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function job_uses_sync_token_from_configuration(): void
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

        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('fetchData');

        $method->invoke($job);

        // Verify syncToken parameter was sent in request
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'syncToken=existing_sync_token');
        });
    }

    #[Test]
    public function job_stores_new_sync_token_in_configuration(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
                'items' => [],
                'nextSyncToken' => 'new_sync_token_xyz',
            ], 200),
        ]);

        $job = new GoogleCalendarEventsPull($this->integration);

        // Use reflection to test protected method
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('fetchData');

        $method->invoke($job);

        // Verify new syncToken was stored
        $this->integration->refresh();
        $this->assertEquals('new_sync_token_xyz', $this->integration->configuration['sync_token']);
    }

    #[Test]
    public function get_integration_returns_integration(): void
    {
        $job = new GoogleCalendarEventsPull($this->integration);

        $this->assertEquals($this->integration->id, $job->getIntegration()->id);
    }
}
