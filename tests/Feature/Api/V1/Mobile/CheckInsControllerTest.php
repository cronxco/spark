<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckInsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/mobile/check-ins
    // -------------------------------------------------------------------------

    #[Test]
    public function store_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())
            ->assertStatus(403);
    }

    #[Test]
    public function store_creates_event_via_plugin(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())
            ->assertStatus(201);

        $this->assertDatabaseHas('events', [
            'service' => 'daily_checkin',
            'action' => 'had_morning_checkin',
            'source_id' => 'daily_checkin_morning_2026-04-19',
        ]);
    }

    #[Test]
    public function store_rejects_out_of_range_ratings(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['physical' => 9]))
            ->assertStatus(422);
    }

    #[Test]
    public function store_is_idempotent_for_same_period_and_date(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['physical' => 5]))->assertStatus(201);

        $this->assertEquals(1, Event::where('source_id', 'daily_checkin_morning_2026-04-19')->count());
    }

    #[Test]
    public function store_persists_notes_in_event_metadata(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['notes' => 'Feeling great today']))
            ->assertStatus(201);

        $event = Event::where('source_id', 'daily_checkin_morning_2026-04-19')->firstOrFail();
        $this->assertEquals('Feeling great today', $event->event_metadata['notes']);
    }

    #[Test]
    public function store_works_without_notes(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())
            ->assertStatus(201);

        $event = Event::where('source_id', 'daily_checkin_morning_2026-04-19')->firstOrFail();
        $this->assertNull($event->event_metadata['notes']);
    }

    #[Test]
    public function store_rejects_notes_exceeding_max_length(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['notes' => str_repeat('a', 1001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/mobile/check-ins?date=YYYY-MM-DD
    // -------------------------------------------------------------------------

    #[Test]
    public function index_returns_completion_status_for_date(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins?date=2026-04-19')
            ->assertStatus(200)
            ->assertJson([
                'date' => '2026-04-19',
                'morning' => ['completed' => false, 'event' => null],
                'afternoon' => ['completed' => false, 'event' => null],
            ]);
    }

    #[Test]
    public function index_shows_completed_when_event_exists(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload())->assertStatus(201);

        $response = $this->getJson('/api/v1/mobile/check-ins?date=2026-04-19')
            ->assertStatus(200);

        $this->assertTrue($response->json('morning.completed'));
        $this->assertNotNull($response->json('morning.event'));
        $this->assertFalse($response->json('afternoon.completed'));
        $this->assertNull($response->json('afternoon.event'));
    }

    #[Test]
    public function index_requires_date_parameter(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    #[Test]
    public function index_requires_valid_date_format(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins?date=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/mobile/check-ins/history?from=YYYY-MM-DD&to=YYYY-MM-DD
    // -------------------------------------------------------------------------

    #[Test]
    public function history_returns_day_by_day_summary(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['date' => '2026-04-19']))->assertStatus(201);
        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['date' => '2026-04-19', 'period' => 'afternoon', 'physical' => 3, 'mental' => 4]))->assertStatus(201);

        $response = $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-18&to=2026-04-20')
            ->assertStatus(200);

        $this->assertEquals('2026-04-18', $response->json('from'));
        $this->assertEquals('2026-04-20', $response->json('to'));

        $days = $response->json('days');
        $this->assertCount(3, $days);

        $targetDay = collect($days)->firstWhere('date', '2026-04-19');
        $this->assertTrue($targetDay['morning']['completed']);
        $this->assertEquals(4, $targetDay['morning']['physical']);
        $this->assertEquals(3, $targetDay['morning']['mental']);
        $this->assertEquals(7, $targetDay['morning']['combined']);
        $this->assertTrue($targetDay['afternoon']['completed']);
        $this->assertFalse(collect($days)->firstWhere('date', '2026-04-18')['morning']['completed']);
    }

    #[Test]
    public function history_rejects_range_exceeding_90_days(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins/history?from=2026-01-01&to=2026-04-15')
            ->assertStatus(422);
    }

    #[Test]
    public function history_rejects_to_before_from(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-20&to=2026-04-10')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    #[Test]
    public function history_requires_read_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:write']);

        $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-10&to=2026-04-19')
            ->assertStatus(403);
    }

    #[Test]
    public function history_includes_notes_when_present(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins', $this->payload(['notes' => 'Great morning']))->assertStatus(201);

        $response = $this->getJson('/api/v1/mobile/check-ins/history?from=2026-04-19&to=2026-04-19')
            ->assertStatus(200);

        $this->assertEquals('Great morning', $response->json('days.0.morning.notes'));
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/mobile/check-ins/media
    // -------------------------------------------------------------------------

    #[Test]
    public function media_requires_authentication(): void
    {
        $this->uploadImage($this->createTestImageContent())->assertStatus(401);
    }

    #[Test]
    public function media_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->uploadImage($this->createTestImageContent())->assertStatus(403);
    }

    #[Test]
    public function media_rejects_unsupported_content_type(): void
    {
        $this->prepareMediaTest();

        $this->uploadImage('not an image', 'application/pdf')->assertStatus(415);
    }

    #[Test]
    public function media_rejects_empty_body(): void
    {
        $this->prepareMediaTest();

        $this->uploadImage('', 'image/jpeg')->assertStatus(422);
    }

    #[Test]
    public function media_stores_photo_and_attaches_it_to_the_day(): void
    {
        $this->prepareMediaTest();

        $this->uploadImage($this->createTestImageContent())
            ->assertStatus(201)
            ->assertJsonPath('action', 'shared_a_photo');

        $event = Event::where('service', 'daily_checkin')
            ->where('action', 'shared_a_photo')
            ->first();

        $this->assertNotNull($event);

        $block = $event->blocks()->where('block_type', 'photo')->first();
        $this->assertNotNull($block);
        $this->assertSame(1, $block->getMedia('downloaded_images')->count());

        // Attached to the day object.
        $this->assertDatabaseHas('objects', [
            'id' => $event->target_id,
            'concept' => 'day',
            'type' => 'day',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/mobile/check-ins/timezone
    // -------------------------------------------------------------------------

    #[Test]
    public function timezone_show_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/check-ins/timezone')->assertStatus(401);
    }

    #[Test]
    public function timezone_show_requires_read_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:write']);

        $this->getJson('/api/v1/mobile/check-ins/timezone')->assertStatus(403);
    }

    #[Test]
    public function timezone_show_falls_back_to_profile_timezone(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins/timezone')
            ->assertStatus(200)
            ->assertExactJson([
                'timezone' => 'Europe/London',
                'source' => 'profile',
                'acknowledged_at' => null,
                'event_id' => null,
                'device_id' => null,
            ]);
    }

    #[Test]
    public function timezone_show_defaults_to_utc_without_profile_timezone(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/check-ins/timezone')
            ->assertStatus(200)
            ->assertJsonPath('timezone', 'UTC')
            ->assertJsonPath('source', 'profile');
    }

    #[Test]
    public function timezone_show_returns_latest_acknowledged_event(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', [
            'timezone' => 'America/New_York',
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/mobile/check-ins/timezone')->assertStatus(200);

        $this->assertSame('America/New_York', $response->json('timezone'));
        $this->assertSame('time_travel', $response->json('source'));
        $this->assertNotNull($response->json('acknowledged_at'));
        $this->assertNotNull($response->json('event_id'));
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/mobile/check-ins/timezone
    // -------------------------------------------------------------------------

    #[Test]
    public function timezone_store_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'America/New_York'])
            ->assertStatus(403);
    }

    #[Test]
    public function timezone_store_creates_single_time_travel_event(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->postJson('/api/v1/mobile/check-ins/timezone', [
            'timezone' => 'America/New_York',
        ])->assertStatus(201);

        $response->assertJsonPath('timezone', 'America/New_York')
            ->assertJsonPath('source', 'time_travel');

        $this->assertSame(1, Event::where('service', 'daily_checkin')->where('action', 'time_travel')->count());

        $event = Event::where('action', 'time_travel')->firstOrFail();
        $this->assertSame('America/New_York', $event->event_metadata['timezone']);
        $this->assertSame('Europe/London', $event->event_metadata['previous_timezone']);
        $this->assertSame('user_acknowledged', $event->event_metadata['source']);
    }

    #[Test]
    public function timezone_store_persists_device_id(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', [
            'timezone' => 'America/New_York',
            'device_id' => 'device-123',
        ])->assertStatus(201)
            ->assertJsonPath('device_id', 'device-123');

        $event = Event::where('action', 'time_travel')->firstOrFail();
        $this->assertSame('device-123', $event->event_metadata['device_id']);
    }

    #[Test]
    public function timezone_store_derives_previous_timezone_ignoring_client_value(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', [
            'timezone' => 'America/New_York',
            'previous_timezone' => 'Asia/Tokyo', // contradictory but valid IANA
        ])->assertStatus(201);

        $event = Event::where('action', 'time_travel')->firstOrFail();
        $this->assertSame('Europe/London', $event->event_metadata['previous_timezone']);
    }

    #[Test]
    public function timezone_store_rejects_invalid_identifier(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'Mars/Phobos'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    #[Test]
    public function timezone_store_rejects_utc_offset_only_value(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => '+05:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    #[Test]
    public function timezone_store_is_idempotent_for_already_effective_zone(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // Submitting the already-effective profile timezone is a no-op.
        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'Europe/London'])
            ->assertStatus(200)
            ->assertJsonPath('source', 'profile');

        $this->assertSame(0, Event::where('action', 'time_travel')->count());

        // Acknowledge a real change, then re-submit the same zone — still no dupe.
        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'America/New_York'])
            ->assertStatus(201);
        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'America/New_York'])
            ->assertStatus(200);

        $this->assertSame(1, Event::where('action', 'time_travel')->count());
    }

    #[Test]
    public function timezone_store_records_subsequent_changes_with_latest_winning(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'America/New_York'])
            ->assertStatus(201);
        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'Asia/Tokyo'])
            ->assertStatus(201);

        $this->assertSame(2, Event::where('action', 'time_travel')->count());

        $this->getJson('/api/v1/mobile/check-ins/timezone')
            ->assertStatus(200)
            ->assertJsonPath('timezone', 'Asia/Tokyo');

        // The second event's derived previous timezone is the first acknowledged zone.
        $latest = Event::where('action', 'time_travel')->orderByDesc('time')->orderByDesc('id')->first();
        $this->assertSame('America/New_York', $latest->event_metadata['previous_timezone']);
    }

    #[Test]
    public function timezone_store_does_not_mutate_profile_timezone(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'America/New_York'])
            ->assertStatus(201);

        $this->assertSame('Europe/London', $this->user->fresh()->getTimezone());
    }

    #[Test]
    public function timezone_state_is_scoped_to_the_authenticated_user(): void
    {
        $this->user->setTimezone('Europe/London');
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $this->postJson('/api/v1/mobile/check-ins/timezone', ['timezone' => 'America/New_York'])
            ->assertStatus(201);

        $other = User::factory()->create();
        $other->setTimezone('Australia/Sydney');
        Sanctum::actingAs($other, ['ios:read']);

        // The other user sees only their own profile fallback, never the first
        // user's acknowledged travel timezone.
        $this->getJson('/api/v1/mobile/check-ins/timezone')
            ->assertStatus(200)
            ->assertJsonPath('timezone', 'Australia/Sydney')
            ->assertJsonPath('source', 'profile');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function prepareMediaTest(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);
        // The photo event/block dispatches the task pipeline on create; keep it out.
        config(['app.enable_task_pipeline' => false]);
    }

    protected function uploadImage(string $content, string $contentType = 'image/jpeg'): TestResponse
    {
        return $this->call('POST', '/api/v1/mobile/check-ins/media', [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => 'application/json',
        ], $content);
    }

    protected function createTestImageContent(): string
    {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($image);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content;
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'period' => 'morning',
            'physical' => 4,
            'mental' => 3,
            'date' => '2026-04-19',
        ], $overrides);
    }

    protected function actingAsUserWithIntegration(): Integration
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'daily_checkin',
        ]);

        return Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'daily_checkin',
            'integration_group_id' => $group->id,
        ]);
    }
}
