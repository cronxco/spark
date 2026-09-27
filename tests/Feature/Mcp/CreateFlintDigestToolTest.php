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
    public function a_topic_task_block_cannot_be_created_inside_a_digest(): void
    {
        SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
            'title' => 'Morning Digest',
            'period' => 'morning',
            'blocks' => [[
                'block_type' => 'flint_topic_task',
                'title' => 'Book the hotel',
            ]],
        ])->assertHasErrors(['block type']);

        $this->assertDatabaseMissing('blocks', ['block_type' => 'flint_topic_task']);
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
            'blocks' => $this->briefingBlocks(),
        ];

        SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, $payload)->assertOk();
        $retry = SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, $payload);

        $retry->assertOk()->assertSee('"deduplicated":true');
        $this->assertSame(1, Event::where('source_id', "flint_digest_run:{$runUuid}")->count());
        $this->assertSame(1, Block::where('title', 'Run notes')->count());
    }

    #[Test]
    public function different_routines_can_write_distinct_digests_in_the_same_period(): void
    {
        foreach ([
            ['digest', 'spark-day-briefing-async'],
            ['news_roundup', 'flint-news-roundup'],
        ] as [$routine, $skill]) {
            $blocks = $routine === 'digest' ? $this->briefingBlocks() : [[
                'block_type' => 'flint_editorial_note',
                'title' => 'Run notes',
                'content' => 'No stories today.',
            ]];
            SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, [
                'title' => $skill,
                'period' => 'morning',
                'date' => today()->toDateString(),
                'run_token' => $this->runToken((string) Str::uuid(), $routine, $skill),
                'blocks' => $blocks,
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

    #[Test]
    public function a_questionless_routine_digest_requires_a_structured_omission(): void
    {
        $blocks = [
            ['block_type' => 'flint_day_context', 'title' => 'Today', 'day_context' => ['calendar' => [], 'birthdays' => []]],
            ['block_type' => 'flint_editorial_note', 'title' => 'Run notes', 'content' => 'No question survived.'],
        ];
        $payload = [
            'title' => 'Morning digest',
            'period' => 'morning',
            'date' => today()->toDateString(),
            'run_token' => $this->runToken((string) Str::uuid(), 'digest', 'spark-day-briefing-async'),
            'blocks' => $blocks,
        ];

        SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, $payload)
            ->assertHasErrors(['questionless digest']);

        $payload['run_token'] = $this->runToken((string) Str::uuid(), 'digest', 'spark-day-briefing-async');
        $payload['question_omission'] = [
            'reason' => 'Every useful uncertainty was already resolved.',
            'candidates' => ['Planning: answered by calendar', 'Money: no ambiguity', 'Health: would be curiosity'],
        ];
        SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, $payload)->assertOk();
    }

    #[Test]
    public function a_news_routine_requires_structured_story_data_and_run_notes_last(): void
    {
        $payload = [
            'title' => 'News roundup',
            'period' => 'morning',
            'date' => today()->toDateString(),
            'run_token' => $this->runToken((string) Str::uuid(), 'news_roundup', 'flint-news-roundup'),
            'blocks' => [
                ['block_type' => 'flint_news', 'title' => 'Rates hold', 'content' => 'The Bank held rates.'],
                ['block_type' => 'flint_editorial_note', 'title' => 'Run notes', 'content' => 'Three sources.'],
            ],
        ];

        SparkServer::actingAs($this->user)->tool(CreateFlintDigestTool::class, $payload)
            ->assertHasErrors(['structured news']);
    }

    #[Test]
    public function a_news_story_can_carry_key_points_and_linked_sources_without_a_summary(): void
    {
        $issueId = (string) Str::uuid();

        SparkServer::actingAs($this->user)
            ->tool(CreateFlintDigestTool::class, $this->newsPayload($this->story($issueId)))
            ->assertOk();

        $block = Block::query()->where('block_type', 'flint_news')->firstOrFail();
        $news = $block->metadata['news'];
        $this->assertSame('Iran offered a seven-day pause; Trump turned it down.', $block->metadata['content']);
        $this->assertCount(3, $news['key_points']);
        $this->assertSame('The Guardian and the NYT differ on whether strikes resume after the midterms.', $news['contested']);
        $this->assertSame($issueId, $news['sources'][0]['event_id']);
        $this->assertSame('feed', $news['sources'][0]['origin']);
        $this->assertSame('https://www.nytimes.com/2026/09/26/us/politics/trump-iran-hormuz-strait.html', $news['sources'][1]['url']);
        $this->assertArrayNotHasKey('summary', $news);
    }

    #[Test]
    public function a_news_story_needs_either_key_points_or_a_summary(): void
    {
        $story = $this->story((string) Str::uuid());
        unset($story['news']['key_points']);

        SparkServer::actingAs($this->user)
            ->tool(CreateFlintDigestTool::class, $this->newsPayload($story))
            ->assertHasErrors(['key_points, or a summary']);
    }

    #[Test]
    public function a_research_source_must_link_to_its_article(): void
    {
        $story = $this->story((string) Str::uuid());
        unset($story['news']['sources'][1]['url']);

        SparkServer::actingAs($this->user)
            ->tool(CreateFlintDigestTool::class, $this->newsPayload($story))
            ->assertHasErrors(['url']);
    }

    #[Test]
    public function a_feed_source_must_point_at_an_event_the_block_references(): void
    {
        $story = $this->story((string) Str::uuid());
        $story['news']['sources'][0]['event_id'] = (string) Str::uuid();

        SparkServer::actingAs($this->user)
            ->tool(CreateFlintDigestTool::class, $this->newsPayload($story))
            ->assertHasErrors(['referenced_event_ids']);
    }

    #[Test]
    public function a_feed_source_must_name_its_issue(): void
    {
        $story = $this->story((string) Str::uuid());
        unset($story['news']['sources'][0]['event_id']);

        SparkServer::actingAs($this->user)
            ->tool(CreateFlintDigestTool::class, $this->newsPayload($story))
            ->assertHasErrors(['event_id']);
    }

    #[Test]
    public function a_key_points_story_needs_its_tldr(): void
    {
        $story = $this->story((string) Str::uuid());
        $story['content'] = '  ';

        SparkServer::actingAs($this->user)
            ->tool(CreateFlintDigestTool::class, $this->newsPayload($story))
            ->assertHasErrors(['TL;DR']);
    }

    #[Test]
    public function key_points_are_limited_to_four(): void
    {
        $story = $this->story((string) Str::uuid());
        $story['news']['key_points'] = ['One.', 'Two.', 'Three.', 'Four.', 'Five.'];

        SparkServer::actingAs($this->user)
            ->tool(CreateFlintDigestTool::class, $this->newsPayload($story))
            ->assertHasErrors(['key_points']);
    }

    /** @return array<string, mixed> */
    private function story(string $issueId): array
    {
        return [
            'block_type' => 'flint_news',
            'title' => 'Trump rejects Iran’s seven-day ceasefire',
            'content' => 'Iran offered a seven-day pause; Trump turned it down.',
            'news' => [
                'key_points' => [
                    'Hormuz would have reopened on the seventh day.',
                    'Iran asked for the blockade to end and an oil-sanctions waiver.',
                    'The NYT puts the frozen assets at $12bn or more.',
                ],
                'contested' => 'The Guardian and the NYT differ on whether strikes resume after the midterms.',
                'sources' => [
                    [
                        'publication' => 'The Economist, World in Brief',
                        'position' => 'Reported the rejection and the blockade claim.',
                        'origin' => 'feed',
                        'event_id' => $issueId,
                    ],
                    [
                        'publication' => 'The New York Times',
                        'position' => 'Set out the proposal’s terms.',
                        'origin' => 'research',
                        'url' => 'https://www.nytimes.com/2026/09/26/us/politics/trump-iran-hormuz-strait.html',
                    ],
                ],
                'what_to_watch' => 'Iran’s formal response through mediators.',
            ],
            'referenced_event_ids' => [$issueId],
        ];
    }

    /** @param array<string, mixed> $story @return array<string, mixed> */
    private function newsPayload(array $story): array
    {
        return [
            'title' => 'News roundup',
            'period' => 'morning',
            'date' => today()->toDateString(),
            'run_token' => $this->runToken((string) Str::uuid(), 'news_roundup', 'flint-news-roundup'),
            'blocks' => [
                $story,
                ['block_type' => 'flint_editorial_note', 'title' => 'Run notes', 'content' => 'Three sources.'],
            ],
        ];
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

    private function briefingBlocks(): array
    {
        return [
            [
                'block_type' => 'flint_day_context',
                'title' => 'Today',
                'day_context' => ['calendar' => [], 'birthdays' => []],
            ],
            [
                'block_type' => 'flint_user_question',
                'title' => 'Useful question',
                'question' => 'Is this still the right plan?',
            ],
            [
                'block_type' => 'flint_editorial_note',
                'title' => 'Run notes',
                'content' => 'Written once.',
            ],
        ];
    }
}
