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
