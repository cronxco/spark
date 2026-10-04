<?php

namespace Tests\Feature\Flint;

use App\Livewire\AnswerFlintQuestion;
use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\AnswerFlintQuestionTool;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\Flint\FlintQuestionActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The web answer form goes through the same question action service as the
 * mobile API, so it records history, can skip, and cannot overwrite an answer
 * given elsewhere since the page was rendered.
 */
class AnswerFlintQuestionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Block $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);
        $digest = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
        ]);
        $this->question = $digest->createBlock([
            'block_type' => 'flint_user_question',
            'title' => 'About that late night',
            'time' => $digest->time,
            'metadata' => ['question' => 'Why were you up so late?', 'priority' => 'high', 'answer' => null],
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function an_answer_is_recorded_with_history(): void
    {
        Livewire::test(AnswerFlintQuestion::class, ['block' => $this->question])
            ->set('answer', 'A late flight')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('answered', true);

        $metadata = $this->question->fresh()->metadata;
        $this->assertSame('A late flight', $metadata['answer']);
        $this->assertSame('answer', $metadata['action_history'][0]['action']);
    }

    #[Test]
    public function a_correction_keeps_the_earlier_answer_in_history(): void
    {
        $component = Livewire::test(AnswerFlintQuestion::class, ['block' => $this->question])
            ->set('answer', 'A late flight')
            ->call('submit');

        $component->call('edit')
            ->assertSee('Save correction')
            ->set('answer', 'A late train')
            ->call('submit')
            ->assertHasNoErrors();

        $metadata = $this->question->fresh()->metadata;
        $this->assertSame('A late train', $metadata['answer']);
        $this->assertSame(['answer', 'correct'], array_column($metadata['action_history'], 'action'));
        $this->assertSame('A late flight', $metadata['action_history'][0]['answer']);
    }

    #[Test]
    public function not_relevant_skips_an_open_question(): void
    {
        Livewire::test(AnswerFlintQuestion::class, ['block' => $this->question])
            ->assertSee('Not relevant')
            ->call('skip')
            ->assertSet('skipped', true)
            ->assertSee('Marked not relevant');

        $this->assertSame('skipped', $this->question->fresh()->metadata['question_status']);
    }

    #[Test]
    public function a_stale_page_cannot_overwrite_an_answer_given_elsewhere(): void
    {
        $component = Livewire::test(AnswerFlintQuestion::class, ['block' => $this->question]);

        app(FlintQuestionActionService::class)->recordLegacy($this->user, (string) $this->question->id, 'From the phone', null);

        $component->set('answer', 'From a stale tab')
            ->call('submit')
            ->assertHasErrors('answer')
            ->assertSee('This question changed elsewhere.');

        $this->assertSame('From the phone', $this->question->fresh()->metadata['answer']);
    }

    #[Test]
    public function another_users_question_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AnswerFlintQuestion::class, ['block' => $this->question])
            ->assertForbidden();
    }

    #[Test]
    public function the_question_card_does_not_show_priority(): void
    {
        $html = view('blocks.types.flint_user_question', ['block' => $this->question->load('event')])->render();

        $this->assertStringNotContainsString('priority', strtolower(strip_tags($html)));
        $this->assertStringContainsString('Why were you up so late?', $html);
    }

    #[Test]
    public function the_mcp_tool_can_mark_a_question_not_relevant(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(AnswerFlintQuestionTool::class, [
            'block_id' => (string) $this->question->id,
            'action' => 'skip',
        ]);

        $response->assertOk();
        $this->assertSame('skipped', $this->question->fresh()->metadata['question_status']);
    }
}
