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
            ->assertJsonMissingPath('data.0.priority');
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

    private function question(mixed $time, array $metadata = []): Block
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => $time,
            'event_metadata' => ['local_date' => $time->toDateString(), 'period' => 'morning'],
        ]);

        return Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_user_question',
            'title' => 'A question',
            'time' => $time,
            'metadata' => array_merge(['question' => 'What should change?', 'answer' => null], $metadata),
        ]);
    }
}
