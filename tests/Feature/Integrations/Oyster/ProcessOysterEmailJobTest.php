<?php

namespace Tests\Feature\Integrations\Oyster;

use App\Jobs\Data\Oyster\LinkOysterJourneyEventsJob;
use App\Jobs\Data\Oyster\ProcessOysterEmailJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessOysterEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oyster',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'oyster',
            'instance_type' => 'journeys',
        ]);

        // Mock TfL API to avoid real HTTP calls
        Http::fake([
            'api.tfl.gov.uk/*' => Http::response([
                'matches' => [[
                    'lat' => 51.5074,
                    'lon' => -0.1278,
                    'name' => 'Test Station',
                    'id' => 'test-naptan-id',
                ]],
            ], 200),
        ]);
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $job = new ProcessOysterEmailJob(
            $this->integration,
            'oyster/test-email.eml'
        );

        $this->assertInstanceOf(ProcessOysterEmailJob::class, $job);
    }

    /** @test */
    public function it_has_correct_timeout()
    {
        $job = new ProcessOysterEmailJob(
            $this->integration,
            'oyster/test-email.eml'
        );

        $this->assertEquals(300, $job->timeout);
    }

    /** @test */
    public function it_generates_unique_id_based_on_integration_and_content()
    {
        $s3Key = 'oyster/test-email-123.eml';
        $job = new ProcessOysterEmailJob($this->integration, $s3Key);

        $uniqueId = $job->uniqueId();

        $this->assertStringStartsWith('process_oyster_email_', $uniqueId);
        $this->assertStringContainsString($this->integration->id, $uniqueId);
    }

    /** @test */
    public function it_processes_csv_and_creates_events()
    {
        Queue::fake([LinkOysterJourneyEventsJob::class]);

        $csvContent = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,17:47,18:14,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",2.80,,8.41,""
28-Nov-2025,07:44,,"Entered Wandle Park tram stop",.00,,8.41,""
CSV;

        // Create a mock email with CSV attachment
        $email = $this->createMockEmail($csvContent);

        $job = new ProcessOysterEmailJob($this->integration, null, $email);
        $job->handle();

        // Should have created Oyster card object
        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'concept' => 'card',
            'type' => 'oyster_card',
        ]);

        // Should have created station objects
        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'type' => 'tfl_station',
            'title' => 'Victoria',
        ]);

        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'type' => 'tfl_station',
            'title' => 'East Croydon',
        ]);

        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'type' => 'tfl_station',
            'title' => 'Wandle Park',
        ]);

        // Should have created touched_in events
        $touchedInEvents = Event::where('integration_id', $this->integration->id)
            ->where('action', 'touched_in_at')
            ->get();

        $this->assertCount(2, $touchedInEvents);

        // Should have created touched_out event (only for National Rail journey)
        $touchedOutEvents = Event::where('integration_id', $this->integration->id)
            ->where('action', 'touched_out_at')
            ->get();

        $this->assertCount(1, $touchedOutEvents);

        // The fare should be on the touched_in event (stored as pence, value_multiplier = 100)
        $nationalRailTouchIn = $touchedInEvents->first(function ($e) {
            return $e->value == 280; // 2.80 pounds = 280 pence
        });

        $this->assertNotNull($nationalRailTouchIn);
        $this->assertEquals('GBP', $nationalRailTouchIn->value_unit);
        $this->assertEquals(100, $nationalRailTouchIn->value_multiplier);

        // Link job should have been dispatched
        Queue::assertPushed(LinkOysterJourneyEventsJob::class);
    }

    /** @test */
    public function it_processes_top_up_entries()
    {
        Queue::fake([LinkOysterJourneyEventsJob::class]);

        $csvContent = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
18-Nov-2024,08:03,,"Topped-up on touch in, Victoria (platforms 9-19) [National Rail]",,15.00,19.11,""
CSV;

        $email = $this->createMockEmail($csvContent);

        $job = new ProcessOysterEmailJob($this->integration, null, $email);
        $job->handle();

        // Should have created topped_up_balance event (value stored as pence)
        $this->assertDatabaseHas('events', [
            'integration_id' => $this->integration->id,
            'action' => 'topped_up_balance',
            'value' => 1500, // 15.00 pounds = 1500 pence
            'value_unit' => 'GBP',
        ]);
    }

    /** @test */
    public function it_creates_correct_transport_mode_metadata()
    {
        Queue::fake([LinkOysterJourneyEventsJob::class]);

        $csvContent = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,17:47,18:14,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",.00,,8.41,""
28-Nov-2025,07:44,,"Entered Wandle Park tram stop",.00,,8.41,""
CSV;

        $email = $this->createMockEmail($csvContent);

        $job = new ProcessOysterEmailJob($this->integration, null, $email);
        $job->handle();

        // Check National Rail event has correct mode
        $nationalRailEvent = Event::where('integration_id', $this->integration->id)
            ->where('action', 'touched_in_at')
            ->whereJsonContains('event_metadata->transport_mode', 'national_rail')
            ->first();

        $this->assertNotNull($nationalRailEvent);
        $this->assertEquals('National Rail', $nationalRailEvent->event_metadata['transport_mode_display']);

        // Check Tram event has correct mode
        $tramEvent = Event::where('integration_id', $this->integration->id)
            ->where('action', 'touched_in_at')
            ->whereJsonContains('event_metadata->transport_mode', 'tram')
            ->first();

        $this->assertNotNull($tramEvent);
        $this->assertEquals('Tram', $tramEvent->event_metadata['transport_mode_display']);
    }

    /** @test */
    public function it_is_idempotent_and_does_not_duplicate_events()
    {
        Queue::fake([LinkOysterJourneyEventsJob::class]);

        $csvContent = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,07:44,,"Entered Wandle Park tram stop",.00,,8.41,""
CSV;

        $email = $this->createMockEmail($csvContent);

        // Process twice
        $job1 = new ProcessOysterEmailJob($this->integration, null, $email);
        $job1->handle();

        $job2 = new ProcessOysterEmailJob($this->integration, null, $email);
        $job2->handle();

        // Should only have one event (not duplicated)
        $events = Event::where('integration_id', $this->integration->id)
            ->where('action', 'touched_in_at')
            ->get();

        $this->assertCount(1, $events);
    }

    /** @test */
    public function it_reuses_existing_station_objects()
    {
        Queue::fake([LinkOysterJourneyEventsJob::class]);

        $csvContent = <<<'CSV'

Date,Start Time,End Time,Journey/Action,Charge,Credit,Balance,Note
28-Nov-2025,17:47,18:14,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",.00,,8.41,""
27-Nov-2025,17:45,18:10,"Victoria (platforms 9-19) [National Rail] to East Croydon [National Rail]",.00,,8.41,""
CSV;

        $email = $this->createMockEmail($csvContent);

        $job = new ProcessOysterEmailJob($this->integration, null, $email);
        $job->handle();

        // Should only have one Victoria station object (reused)
        $victoriaStations = EventObject::where('user_id', $this->user->id)
            ->where('type', 'tfl_station')
            ->where('title', 'Victoria')
            ->get();

        $this->assertCount(1, $victoriaStations);

        // But both events should reference the same station
        $touchedInEvents = Event::where('integration_id', $this->integration->id)
            ->where('action', 'touched_in_at')
            ->get();

        $this->assertCount(2, $touchedInEvents);

        // Both should have the same target (Victoria station)
        $this->assertEquals($touchedInEvents[0]->target_id, $touchedInEvents[1]->target_id);
    }

    /**
     * Create a mock email with CSV attachment
     */
    private function createMockEmail(string $csvContent, ?string $pdfContent = null): string
    {
        $boundary = md5(uniqid());

        $email = "From: tfl@email.tfl.gov.uk\r\n";
        $email .= "To: test@example.com\r\n";
        $email .= "Subject: Your Oyster journey statement\r\n";
        $email .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

        // Text part
        $email .= "--{$boundary}\r\n";
        $email .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
        $email .= "Your weekly Oyster journey statement is attached.\r\n\r\n";

        // CSV attachment
        $email .= "--{$boundary}\r\n";
        $email .= "Content-Type: text/csv; name=\"journey-history.csv\"\r\n";
        $email .= "Content-Disposition: attachment; filename=\"journey-history.csv\"\r\n\r\n";
        $email .= $csvContent."\r\n\r\n";

        $email .= "--{$boundary}--\r\n";

        return $email;
    }
}
