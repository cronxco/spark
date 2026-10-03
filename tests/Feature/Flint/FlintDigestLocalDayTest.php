<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\GetLatestFlintDigestTool;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\FlintDigestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Decision D-F2: digest storage is unchanged, and every reader finds a digest
 * by the local day it was written for. A New York day is the case that used to
 * miss, because the stored local midnight falls before the day's UTC bounds.
 */
class FlintDigestLocalDayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $digestId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true, 'app.enable_task_pipeline' => false]);
        Carbon::setTestNow(Carbon::parse('2026-06-14 21:00:00', 'America/New_York'));

        $this->user = User::factory()->create();
        $this->user->setTimezone('America/New_York');
        Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint', 'instance_type' => 'assistant']);

        $this->digestId = app(FlintDigestService::class)->create($this->user, [
            'title' => 'Evening Digest',
            'period' => 'evening',
            'date' => '2026-06-14',
        ])['event_id'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_mobile_day_query_finds_a_new_york_digest(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests?date=2026-06-14')
            ->assertOk()
            ->assertJsonPath('event_id', $this->digestId)
            ->assertJsonPath('date', '2026-06-14');

        $this->getJson('/api/v1/mobile/flint/digests')
            ->assertOk()
            ->assertJsonPath('event_id', $this->digestId);
    }

    #[Test]
    public function mobile_history_finds_a_new_york_digest_on_its_own_day_only(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests?from=2026-06-14&to=2026-06-14')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->digestId);

        $this->getJson('/api/v1/mobile/flint/digests?from=2026-06-13&to=2026-06-13')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_mcp_reader_finds_a_new_york_digest(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, ['date' => '2026-06-14']);

        $response->assertOk()->assertSee($this->digestId);
    }

    #[Test]
    public function the_already_ran_check_sees_a_new_york_digest(): void
    {
        $event = Event::findOrFail($this->digestId);
        $event->update(['event_metadata' => array_merge($event->event_metadata, ['routine' => 'digest', 'trigger_source' => 'scheduled'])]);

        $job = new TriggerFlintDigestRoutineJob($this->user, 'evening', '2026-06-14', 'America/New_York', 'scheduled');
        $exists = new ReflectionMethod($job, 'digestAlreadyExists');

        $this->assertTrue($exists->invoke($job));
    }
}
