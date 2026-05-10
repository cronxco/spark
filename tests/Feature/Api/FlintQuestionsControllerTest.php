<?php

namespace Tests\Feature\Api;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintQuestionsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Block $questionBlock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        $this->questionBlock = Block::factory()->create([
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
    }

    #[Test]
    public function stores_answer_in_block_metadata(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/flint/questions/{$this->questionBlock->id}/answer", [
            'answer' => 'Yes',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['answer' => 'Yes']);

        $this->questionBlock->refresh();
        $this->assertEquals('Yes', $this->questionBlock->metadata['answer']);
        $this->assertNotNull($this->questionBlock->metadata['answered_at']);
    }

    #[Test]
    public function stores_answer_note_when_provided(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/flint/questions/{$this->questionBlock->id}/answer", [
            'answer' => 'No',
            'answer_note' => 'Was stressed yesterday.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['answer_note' => 'Was stressed yesterday.']);

        $this->questionBlock->refresh();
        $this->assertEquals('Was stressed yesterday.', $this->questionBlock->metadata['answer_note']);
    }

    #[Test]
    public function returns_403_for_block_owned_by_other_user(): void
    {
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/flint/questions/{$this->questionBlock->id}/answer", [
            'answer' => 'Yes',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function returns_422_for_wrong_block_type(): void
    {
        Sanctum::actingAs($this->user);

        $event = Event::factory()->create([
            'integration_id' => $this->questionBlock->event->integration_id,
        ]);

        $editorialBlock = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_editorial_note',
            'title' => 'Some Note',
            'metadata' => ['content' => 'Note content'],
        ]);

        $response = $this->postJson("/api/flint/questions/{$editorialBlock->id}/answer", [
            'answer' => 'Something',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function returns_401_when_unauthenticated(): void
    {
        $response = $this->postJson("/api/flint/questions/{$this->questionBlock->id}/answer", [
            'answer' => 'Yes',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function validates_answer_is_required(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/flint/questions/{$this->questionBlock->id}/answer", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['answer']);
    }

    #[Test]
    public function validates_answer_max_length(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/flint/questions/{$this->questionBlock->id}/answer", [
            'answer' => str_repeat('x', 1001),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['answer']);
    }
}
