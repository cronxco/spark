<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\GetLatestFlintDigestTool;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetLatestFlintDigestToolTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);
    }

    #[Test]
    public function returns_todays_latest_digest_by_default(): void
    {
        $event = $this->createDigestEvent('morning');

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, []);

        $response->assertOk();
        $response->assertSee('"event_id": "' . $event->id . '"');
        $response->assertSee('"period": "morning"');
    }

    #[Test]
    public function returns_error_when_no_digest_found(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, [
            'date' => '2020-01-01',
        ]);

        $response->assertHasErrors(['No Flint digest found']);
    }

    #[Test]
    public function exposes_full_metadata_for_user_question_blocks(): void
    {
        $event = $this->createDigestEvent('morning');

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Sleep Check',
            'metadata' => [
                'question' => 'Did you sleep well?',
                'topic' => 'health',
                'priority' => 'high',
                'answer_options' => ['Yes', 'No'],
                'answer' => 'Yes',
                'answer_note' => 'Felt great',
                'answered_at' => '2026-05-10T08:00:00+00:00',
            ],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, []);

        $response->assertOk();
        $response->assertSee('"question": "Did you sleep well?"');
        $response->assertSee('"answer": "Yes"');
        $response->assertSee('"answer_note": "Felt great"');
        $response->assertSee('"answered": true');
    }

    #[Test]
    public function unanswered_question_blocks_have_null_answer(): void
    {
        $event = $this->createDigestEvent('morning');

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Mood Check',
            'metadata' => [
                'question' => 'How are you feeling today?',
                'answer' => null,
                'answer_note' => null,
                'answered_at' => null,
            ],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, []);

        $response->assertOk();
        $response->assertSee('"answer": null');
        $response->assertSee('"answered": false');
    }

    #[Test]
    public function filters_by_period_when_provided(): void
    {
        $this->createDigestEvent('morning');
        $pmEvent = $this->createDigestEvent('afternoon');

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, [
            'period' => 'afternoon',
        ]);

        $response->assertOk();
        $response->assertSee('"event_id": "' . $pmEvent->id . '"');
        $response->assertSee('"period": "afternoon"');
    }

    #[Test]
    public function only_returns_digests_owned_by_authenticated_user(): void
    {
        $otherUser = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::today()->midDay(),
            'event_metadata' => ['period' => 'morning'],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, []);

        $response->assertHasErrors(['No Flint digest found']);
    }

    /**
     * A day-context block keeps its payload in metadata, not content. The REST
     * controller unwrapped it and this tool did not, so the calendar and
     * weather came back as `"content": null` — invisible to the Flint routines
     * that read their own digests back.
     */
    #[Test]
    public function returns_the_structured_payload_of_a_day_context_block(): void
    {
        $event = $this->createDigestEvent('morning');

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_day_context',
            'title' => 'Today at a glance',
            'metadata' => [
                'day_context' => [
                    'calendar' => [['title' => 'Will · Office', 'all_day' => false, 'start' => null, 'person' => 'will']],
                    'birthdays' => [['title' => "Daniel's birthday"]],
                    'weather' => ['location' => 'Newquay', 'condition' => 'Overcast', 'temp_high_c' => 20, 'rain_probability_pct' => 8],
                ],
            ],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, []);

        $response->assertOk();
        $response->assertSee('Will · Office');
        $response->assertSee('Newquay');
        $response->assertSee("Daniel's birthday");
    }

    /**
     * A digest is filed at the start of the user's local day, which for anyone
     * east or west of UTC is a different UTC calendar date. whereDate() compares
     * the stored UTC date, so pairing it with a timezone-aware "today" silently
     * mixed two notions of the same day and could return nothing.
     */
    #[Test]
    public function finds_a_digest_filed_at_the_start_of_a_local_day_ahead_of_utc(): void
    {
        $this->user->setTimezone('Australia/Sydney');
        $localDay = Carbon::parse('2026-09-12', 'Australia/Sydney')->startOfDay();

        // 2026-09-12 00:00 in Sydney is 2026-09-11 14:00 UTC — a different
        // UTC calendar date from the one being asked for.
        $this->assertSame('2026-09-11', $localDay->copy()->setTimezone('UTC')->toDateString());

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => $localDay,
            'event_metadata' => ['period' => 'morning', 'title' => 'Morning Digest'],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, [
            'date' => '2026-09-12',
        ]);

        $response->assertOk();
        $response->assertSee('Morning Digest');
    }

    #[Test]
    public function does_not_return_a_digest_from_the_neighbouring_local_day(): void
    {
        $this->user->setTimezone('Australia/Sydney');

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::parse('2026-09-13', 'Australia/Sydney')->startOfDay(),
            'event_metadata' => ['period' => 'morning', 'title' => 'Tomorrow Digest'],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, [
            'date' => '2026-09-12',
        ]);

        $response->assertHasErrors(['No Flint digest found']);
    }

    /** Citations are useless if only the writer can see them. */
    #[Test]
    public function returns_referenced_event_ids_on_a_content_block(): void
    {
        $event = $this->createDigestEvent('morning');
        $referenced = (string) Str::uuid();

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_news',
            'title' => 'A story',
            'metadata' => [
                'content' => 'Something happened.',
                'referenced_event_ids' => [$referenced],
                'news' => [
                    'summary' => 'Something happened.',
                    'sources' => [['publication' => 'The Economist', 'position' => 'Reported the change.']],
                    'what_to_watch' => 'The next vote.',
                ],
            ],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, []);

        $response->assertOk();
        $response->assertSee($referenced);
        $response->assertSee('what_to_watch');
        $response->assertSee('The next vote');
    }

    #[Test]
    public function returns_note_ids_used_for_one_off_instruction_deduplication(): void
    {
        $noteId = (string) Str::uuid();
        $this->createDigestEvent('morning', eventMeta: ['note_ids_used' => [$noteId]]);

        SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, [])
            ->assertOk()
            ->assertSee($noteId);
    }

    /**
     * The layout a client picks must not depend on how the digest happened to
     * be named. A reading list is a reading list even when its title says
     * nothing.
     */
    #[Test]
    public function reports_the_digest_kind_from_the_routine_not_the_title(): void
    {
        $this->createDigestEvent('evening', eventMeta: [
            'title' => 'Anything At All',
            'routine' => 'reading_list',
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetLatestFlintDigestTool::class, []);

        $response->assertOk();
        $response->assertSee('"kind": "reading_list"');
    }

    #[Test]
    public function returns_error_when_unauthenticated(): void
    {
        $response = SparkServer::tool(GetLatestFlintDigestTool::class, []);

        $response->assertHasErrors(['Authentication required']);
    }

    private function createDigestEvent(string $period = 'morning', ?Carbon $date = null, array $eventMeta = []): Event
    {
        $date ??= Carbon::today();

        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => $date->midDay(),
            'event_metadata' => array_merge([
                'period' => $period,
                'title' => ucfirst($period) . ' Digest',
            ], $eventMeta),
        ]);
    }
}
