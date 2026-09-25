<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintQuestionsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true, 'app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
    }

    #[Test]
    public function open_questions_are_not_limited_to_todays_digest(): void
    {
        $open = $this->question(now()->subDays(3));
        $this->question(now()->subDay(), ['answer' => 'Done', 'answered_at' => now()->toIso8601String()]);
        $this->question(now()->subDay(), ['question_status' => 'skipped', 'skipped_at' => now()->toIso8601String()]);
        $this->question(now()->subDays(8));

        $this->getJson('/api/v1/mobile/flint/questions?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('next_cursor', null)
            ->assertJsonPath('has_more', false)
            ->assertJsonMissingPath('data.0.priority');
    }

    #[Test]
    public function question_resource_exposes_a_title(): void
    {
        $this->question(now());

        $this->getJson('/api/v1/mobile/flint/questions?status=open')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A question');
    }

    #[Test]
    public function asked_at_is_when_the_question_was_written_not_its_digest_day(): void
    {
        // A digest's blocks carry the digest's local day as `time`, so the
        // morning and evening questions share it; the evening question is
        // given the lower UUID so an `id` tiebreak would put it second.
        $dayStart = now()->startOfDay();
        $this->question($dayStart, overrides: [
            'id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff',
            'created_at' => $dayStart->copy()->setTime(6, 20),
        ]);
        $evening = $this->question($dayStart, overrides: [
            'id' => '00000000-0000-4000-8000-000000000000',
            'created_at' => $dayStart->copy()->setTime(18, 36),
        ]);

        $response = $this->getJson('/api/v1/mobile/flint/questions?status=open')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $evening->id);

        $this->assertTrue(
            $dayStart->copy()->setTime(18, 36)->equalTo($response->json('data.0.asked_at')),
            'asked_at should be the question block\'s created_at'
        );
    }

    #[Test]
    public function status_accepts_a_comma_separated_list(): void
    {
        $open = $this->question(now()->subDay());
        $answered = $this->question(now()->subDay(), ['answer' => 'Done', 'answered_at' => now()->toIso8601String()]);
        $this->question(now()->subDay(), ['question_status' => 'skipped', 'skipped_at' => now()->toIso8601String()]);

        $response = $this->getJson('/api/v1/mobile/flint/questions?status=open,answered&since=7d')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertTrue($ids->contains($answered->id));
    }

    #[Test]
    public function since_bounds_the_query_server_side(): void
    {
        $recent = $this->question(now()->subHours(10), ['answer' => 'Done', 'answered_at' => now()->toIso8601String()]);
        $this->question(now()->subDays(10), ['answer' => 'Done', 'answered_at' => now()->toIso8601String()]);

        $this->getJson('/api/v1/mobile/flint/questions?status=answered&since=48h')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id);
    }

    #[Test]
    public function since_counts_from_when_a_question_was_asked(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(12, 0));

        // Asked at 21:00 yesterday, filed under yesterday's midnight: 15 hours
        // ago by `asked_at`, 36 by its digest's day.
        $lastNight = $this->question(now()->subDay()->startOfDay(), overrides: [
            'created_at' => now()->subDay()->setTime(21, 0),
        ]);

        $this->getJson('/api/v1/mobile/flint/questions?status=open&since=24h')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lastNight->id);
    }

    #[Test]
    public function status_skipped_and_retired_are_independently_filterable(): void
    {
        $skipped = $this->question(now()->subDay(), ['question_status' => 'skipped', 'skipped_at' => now()->toIso8601String()]);
        $retired = $this->question(now()->subDays(10), ['retired_at' => now()->toIso8601String()]);

        $this->getJson('/api/v1/mobile/flint/questions?status=skipped&since=30d')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $skipped->id);

        $this->getJson('/api/v1/mobile/flint/questions?status=retired&since=30d')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retired->id);
    }

    #[Test]
    public function rejects_an_unknown_status(): void
    {
        $this->getJson('/api/v1/mobile/flint/questions?status=bogus')->assertStatus(422);
    }

    #[Test]
    public function rejects_an_unparseable_since(): void
    {
        $this->getJson('/api/v1/mobile/flint/questions?since=not-a-window')->assertStatus(422);
    }

    #[Test]
    public function answer_correction_and_replay_preserve_complete_history(): void
    {
        $question = $this->question(now());
        $initialVersion = app(ResourceVersion::class)->etag($question);
        $mutation = (string) Str::uuid();
        $answer = $this->postJson("/api/v1/mobile/flint/questions/{$question->id}/actions", [
            'action' => 'answer', 'answer' => 'Friday', 'context' => 'Thursday clashes.',
        ], ['If-Match' => $initialVersion, 'Idempotency-Key' => $mutation])
            ->assertCreated()
            ->assertJsonPath('data.status', 'answered')
            ->assertJsonCount(1, 'data.answer_history');

        $this->postJson("/api/v1/mobile/flint/questions/{$question->id}/actions", [
            'action' => 'answer', 'answer' => 'Friday', 'context' => 'Thursday clashes.',
        ], ['If-Match' => $initialVersion, 'Idempotency-Key' => $mutation])
            ->assertOk()
            ->assertJsonCount(1, 'data.answer_history');

        $this->postJson("/api/v1/mobile/flint/questions/{$question->id}/actions", [
            'action' => 'correct', 'answer' => 'Monday',
        ], ['If-Match' => $answer->headers->get('ETag'), 'Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('data.effective_answer.answer', 'Monday')
            ->assertJsonCount(2, 'data.answer_history');
    }

    #[Test]
    public function action_preconditions_and_mutation_conflicts_are_enforced(): void
    {
        $question = $this->question(now());
        $url = "/api/v1/mobile/flint/questions/{$question->id}/actions";
        $mutation = (string) Str::uuid();

        $this->postJson($url, ['action' => 'skip'], ['Idempotency-Key' => $mutation])->assertStatus(428);
        $version = app(ResourceVersion::class)->etag($question);
        $this->postJson($url, ['action' => 'skip'], ['If-Match' => $version, 'Idempotency-Key' => $mutation])->assertCreated();
        $this->postJson($url, ['action' => 'answer', 'answer' => 'Different'], ['If-Match' => $version, 'Idempotency-Key' => $mutation])->assertConflict();
    }

    #[Test]
    public function malformed_history_entries_are_ignored_and_missing_ids_are_stable(): void
    {
        $question = $this->question(now(), [
            'answer' => 'Friday',
            'answered_at' => now()->toIso8601String(),
            'action_history' => [
                ['answer' => 'Missing action'],
                ['action' => 'unknown'],
                ['action' => 'answer', 'answer' => 'Friday', 'created_at' => now()->toIso8601String()],
            ],
        ]);

        $response = $this->getJson("/api/v1/mobile/flint/digests/{$question->event_id}")
            ->assertOk()
            ->assertJsonCount(1, 'blocks.0.answer_history')
            ->assertJsonPath('blocks.0.answer_history.0.action', 'answer');

        $this->assertNotEmpty($response->json('blocks.0.answer_history.0.id'));
    }

    #[Test]
    public function the_legacy_mobile_adapter_preserves_transition_errors(): void
    {
        $question = $this->question(now(), [
            'question_status' => 'skipped',
            'skipped_at' => now()->toIso8601String(),
        ]);

        $this->postJson("/api/v1/mobile/flint/questions/{$question->id}/answer", ['answer' => 'Friday'])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Only open or retired questions can be answered.');
    }

    private function question(mixed $time, array $metadata = [], array $overrides = []): Block
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => $time,
            'event_metadata' => ['local_date' => $time->toDateString(), 'period' => 'morning'],
        ]);

        return Block::factory()->create(array_merge([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'A question',
            'time' => $time,
            // A question is written when its digest runs.
            'created_at' => $time,
            'metadata' => array_merge(['question' => 'What should change?', 'answer' => null], $metadata),
        ], $overrides));
    }
}
