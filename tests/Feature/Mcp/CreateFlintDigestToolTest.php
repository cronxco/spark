<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\CreateFlintDigestTool;
use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
