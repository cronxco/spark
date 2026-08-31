<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\CreateFlintDigestTool;
use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateFlintDigestToolTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function creates_event_and_blocks_from_valid_input(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
            'title' => 'Morning Digest',
            'period' => 'morning',
            'date' => today()->toDateString(),
            'blocks' => [
                [
                    'block_type' => 'flint_editorial_note',
                    'title' => 'Spending Note',
                    'content' => 'Food delivery spending is up 3x this week.',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertSee('"period":"morning"');
        $response->assertSee('"block_count":1');

        $this->assertDatabaseHas('events', [
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        $this->assertDatabaseHas('blocks', [
            'block_type' => 'flint_editorial_note',
            'title' => 'Spending Note',
        ]);
    }

    #[Test]
    public function creates_digest_with_no_blocks(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
            'title' => 'Quick Summary',
            'period' => 'afternoon',
        ]);

        $response->assertOk();
        $response->assertSee('"block_count":0');

        $this->assertDatabaseHas('events', [
            'service' => 'flint',
            'action' => 'had_summary',
        ]);
    }

    #[Test]
    public function user_question_blocks_initialise_with_null_answer(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
            'title' => 'Evening Digest',
            'period' => 'evening',
            'date' => today()->toDateString(),
            'blocks' => [
                [
                    'block_type' => 'flint_user_question',
                    'title' => 'Sleep Check',
                    'question' => 'You slept 5h last night, below your 7h baseline. How do you feel?',
                    'topic' => 'health',
                    'priority' => 'high',
                    'answer_options' => ['Fine', 'Tired', 'Very tired'],
                ],
            ],
        ]);

        $response->assertOk();

        $block = Block::where('block_type', 'flint_user_question')
            ->where('title', 'Sleep Check')
            ->first();

        $this->assertNotNull($block);
        $this->assertNull($block->metadata['answer']);
        $this->assertNull($block->metadata['answer_note']);
        $this->assertNull($block->metadata['answered_at']);
        $this->assertEquals('high', $block->metadata['priority']);
        $this->assertEquals(['Fine', 'Tired', 'Very tired'], $block->metadata['answer_options']);
    }

    #[Test]
    public function creates_flint_integration_and_digest_object(): void
    {
        $this->assertDatabaseMissing('integrations', [
            'user_id' => $this->user->id,
            'service' => 'flint',
        ]);

        SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
            'title' => 'Morning Digest',
            'period' => 'morning',
        ]);

        $this->assertDatabaseHas('integrations', [
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'concept' => 'digest',
            'type' => 'morning_digest',
        ]);
    }

    #[Test]
    public function infers_period_from_current_time_when_omitted(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
            'title' => 'Auto Digest',
        ]);

        $response->assertOk();

        // A digest event should have been created with an inferred period
        $this->assertDatabaseHas('events', [
            'service' => 'flint',
            'action' => 'had_summary',
        ]);
    }

    #[Test]
    public function returns_error_when_unauthenticated(): void
    {
        $response = SparkServer::tool(CreateFlintDigestTool::class, [
            'title' => 'Morning Digest',
        ]);

        $response->assertHasErrors(['Authentication required']);
    }

    #[Test]
    public function returns_error_when_title_is_missing(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
            'period' => 'morning',
        ]);

        $response->assertHasErrors(['title field is required']);
    }

    #[Test]
    public function a_run_token_makes_callback_retries_durably_idempotent(): void
    {
        $runUuid = (string) Str::uuid();
        $token = $this->runToken($runUuid, 'digest', 'spark-day-briefing-async');
        $payload = [
            'title' => 'Run-bound digest',
            'period' => 'morning',
            'date' => today()->toDateString(),
            'run_token' => $token,
            'blocks' => [[
                'block_type' => 'flint_editorial_note',
                'title' => 'One block',
                'content' => 'Written once.',
            ]],
        ];

        SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, $payload)->assertOk();
        $retry = SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, $payload);

        $retry->assertOk()->assertSee('"deduplicated":true');
        $this->assertSame(1, Event::where('source_id', "flint_digest_run:{$runUuid}")->count());
        $this->assertSame(1, Block::where('title', 'One block')->count());
    }

    #[Test]
    public function different_routines_can_write_distinct_digests_in_the_same_period(): void
    {
        foreach ([
            ['digest', 'spark-day-briefing-async'],
            ['news_roundup', 'flint-news-roundup'],
        ] as [$routine, $skill]) {
            SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
                'title' => $skill,
                'period' => 'morning',
                'date' => today()->toDateString(),
                'run_token' => $this->runToken((string) Str::uuid(), $routine, $skill),
            ])->assertOk();
        }

        $this->assertSame(2, Event::where('service', 'flint')->where('action', 'had_summary')->count());
        $this->assertSame(
            ['digest', 'news_roundup'],
            Event::where('service', 'flint')->get()->pluck('event_metadata.routine')->sort()->values()->all(),
        );
    }

    #[Test]
    public function a_run_token_is_bound_to_its_user_date_and_period(): void
    {
        $other = User::factory()->create();
        $token = $this->runToken((string) Str::uuid(), 'digest', 'spark-day-briefing-async');

        SparkServer::actingAs($other)->tool(CreateFlintDigestTool::class, [
            'title' => 'Wrong owner',
            'period' => 'morning',
            'date' => today()->toDateString(),
            'run_token' => $token,
        ])->assertHasErrors(['does not match']);
    }

    private function runToken(string $runUuid, string $routine, string $skill): string
    {
        return app(FlintRunToken::class)->issue([
            'run_uuid' => $runUuid,
            'user_id' => (string) $this->user->id,
            'routine' => $routine,
            'skill' => $skill,
            'local_date' => today()->toDateString(),
            'period' => 'morning',
            'trigger_source' => 'scheduled',
        ]);
    }
}
