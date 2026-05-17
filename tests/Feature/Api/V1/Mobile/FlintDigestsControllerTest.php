<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintDigestsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /flint/digests
    // -------------------------------------------------------------------------

    #[Test]
    public function index_returns_todays_latest_digest_by_default(): void
    {
        $event = $this->createDigestEvent('morning');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests')
            ->assertOk()
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('period', 'morning');
    }

    #[Test]
    public function index_returns_404_when_no_digest_exists(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests?date=2020-01-01')
            ->assertNotFound()
            ->assertJsonPath('error', 'No Flint digest found for 2020-01-01.');
    }

    #[Test]
    public function index_filters_by_period(): void
    {
        $this->createDigestEvent('morning');
        $pmEvent = $this->createDigestEvent('afternoon');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests?period=afternoon')
            ->assertOk()
            ->assertJsonPath('event_id', $pmEvent->id)
            ->assertJsonPath('period', 'afternoon');
    }

    #[Test]
    public function index_returns_all_digests_when_all_flag_set(): void
    {
        $this->createDigestEvent('morning');
        $this->createDigestEvent('afternoon');

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests?all=true')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonStructure(['date', 'count', 'digests']);
    }

    #[Test]
    public function index_only_returns_digests_for_authenticated_user(): void
    {
        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
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

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests')
            ->assertNotFound();
    }

    #[Test]
    public function index_returns_unanswered_question_count(): void
    {
        $event = $this->createDigestEvent('morning');

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Q1',
            'metadata' => ['question' => 'How are you?', 'answer' => null, 'answer_note' => null, 'answered_at' => null],
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Q2',
            'metadata' => ['question' => 'Sleep well?', 'answer' => 'Yes', 'answer_note' => null, 'answered_at' => now()->toIso8601String()],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests')
            ->assertOk()
            ->assertJsonPath('unanswered_question_count', 1)
            ->assertJsonPath('block_count', 2);
    }

    #[Test]
    public function content_blocks_expose_resolved_references_and_linkified_prose(): void
    {
        $event = $this->createDigestEvent('morning');

        $referenced = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'testsvc',
            'domain' => 'health',
            'action' => 'morning_walk',
            'time' => Carbon::today()->midDay(),
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_health_insight',
            'title' => 'Health',
            'metadata' => [
                'content' => 'You did a Morning Walk before breakfast.',
                'referenced_event_ids' => [$referenced->id],
            ],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $response = $this->getJson('/api/v1/mobile/flint/digests')
            ->assertOk()
            ->assertJsonPath('blocks.0.references.0.type', 'event')
            ->assertJsonPath('blocks.0.references.0.id', $referenced->id)
            ->assertJsonPath('blocks.0.references.0.title', 'Morning Walk')
            ->assertJsonPath('blocks.0.references.0.domain', 'health');

        $this->assertStringContainsString(
            '[Morning Walk](https://spark.cronx.co/event/' . $referenced->id . ')',
            $response->json('blocks.0.content'),
        );
    }

    #[Test]
    public function content_blocks_without_references_omit_the_key(): void
    {
        $event = $this->createDigestEvent('morning');

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_editorial_note',
            'title' => 'Note',
            'metadata' => ['content' => 'Just a plain note.'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests')
            ->assertOk()
            ->assertJsonPath('blocks.0.content', 'Just a plain note.')
            ->assertJsonMissingPath('blocks.0.references');
    }

    // -------------------------------------------------------------------------
    // GET /flint/digests/{id}
    // -------------------------------------------------------------------------

    #[Test]
    public function show_returns_digest_with_full_block_detail(): void
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
                'answer' => null,
                'answer_note' => null,
                'answered_at' => null,
            ],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests/' . $event->id)
            ->assertOk()
            ->assertJsonPath('event_id', $event->id)
            ->assertJsonPath('period', 'morning')
            ->assertJsonPath('blocks.0.block_type', 'flint_user_question')
            ->assertJsonPath('blocks.0.question', 'Did you sleep well?')
            ->assertJsonPath('blocks.0.priority', 'high')
            ->assertJsonPath('blocks.0.answered', false);
    }

    #[Test]
    public function show_returns_404_for_another_users_digest(): void
    {
        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::today()->midDay(),
            'event_metadata' => ['period' => 'morning'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests/' . $event->id)
            ->assertNotFound();
    }

    #[Test]
    public function show_returns_404_for_unknown_id(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->getJson('/api/v1/mobile/flint/digests/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // POST /flint/questions/{block}/answer
    // -------------------------------------------------------------------------

    #[Test]
    public function answer_stores_the_users_response(): void
    {
        $event = $this->createDigestEvent('morning');

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Mood Check',
            'metadata' => ['question' => 'How are you?', 'answer' => null, 'answer_note' => null, 'answered_at' => null],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/flint/questions/' . $block->id . '/answer', [
            'answer' => 'Good',
            'answer_note' => 'Feeling productive today',
        ])
            ->assertOk()
            ->assertJsonPath('block_id', $block->id)
            ->assertJsonPath('answer', 'Good')
            ->assertJsonPath('answer_note', 'Feeling productive today');

        $this->assertEquals('Good', $block->fresh()->metadata['answer']);
        $this->assertEquals('Feeling productive today', $block->fresh()->metadata['answer_note']);
        $this->assertNotNull($block->fresh()->metadata['answered_at']);
    }

    #[Test]
    public function answer_works_without_answer_note(): void
    {
        $event = $this->createDigestEvent('morning');

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Quick Q',
            'metadata' => ['question' => 'Yes or no?', 'answer' => null, 'answer_note' => null, 'answered_at' => null],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/flint/questions/' . $block->id . '/answer', [
            'answer' => 'Yes',
        ])
            ->assertOk()
            ->assertJsonPath('answer_note', null);
    }

    #[Test]
    public function answer_requires_write_ability(): void
    {
        $event = $this->createDigestEvent('morning');

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Q',
            'metadata' => ['question' => 'Test?', 'answer' => null, 'answer_note' => null, 'answered_at' => null],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/flint/questions/' . $block->id . '/answer', [
            'answer' => 'Yes',
        ])->assertStatus(403);
    }

    #[Test]
    public function answer_returns_403_for_block_owned_by_another_user(): void
    {
        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::today()->midDay(),
            'event_metadata' => ['period' => 'morning'],
        ]);

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Q',
            'metadata' => ['question' => 'Test?', 'answer' => null, 'answer_note' => null, 'answered_at' => null],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/flint/questions/' . $block->id . '/answer', [
            'answer' => 'Yes',
        ])->assertStatus(403);
    }

    #[Test]
    public function answer_returns_422_for_non_question_block(): void
    {
        $event = $this->createDigestEvent('morning');

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_editorial_note',
            'title' => 'Note',
            'metadata' => ['content' => 'Some note'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/flint/questions/' . $block->id . '/answer', [
            'answer' => 'Yes',
        ])->assertStatus(422);
    }

    #[Test]
    public function answer_validates_answer_is_required(): void
    {
        $event = $this->createDigestEvent('morning');

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'Q',
            'metadata' => ['question' => 'Test?', 'answer' => null, 'answer_note' => null, 'answered_at' => null],
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/flint/questions/' . $block->id . '/answer', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answer']);
    }

    #[Test]
    public function answer_returns_401_when_unauthenticated(): void
    {
        $this->postJson('/api/v1/mobile/flint/questions/some-id/answer', [
            'answer' => 'Yes',
        ])->assertUnauthorized();
    }

    private function createDigestEvent(string $period = 'morning', ?Carbon $date = null, array $meta = []): Event
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
            ], $meta),
        ]);
    }
}
