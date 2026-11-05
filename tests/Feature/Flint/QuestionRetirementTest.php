<?php

namespace Tests\Feature\Flint;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\Flint\FlintQuestionAnswerer;
use App\Services\FlintDigestService;
use App\Support\FlintBlockPresenter;
use App\Support\FlintQuestion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A question unanswered for a week is retired: it stops presenting as
 * outstanding, without being deleted or made unanswerable.
 */
class QuestionRetirementTest extends TestCase
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
    public function a_question_unanswered_past_the_horizon_is_retired_when_the_next_digest_is_written(): void
    {
        $stale = $this->createQuestion(Carbon::now()->subDays(FlintQuestion::RETIREMENT_DAYS + 1));

        app(FlintDigestService::class)->create($this->user, [
            'title' => 'Morning Digest',
            'period' => 'morning',
        ]);

        $stale->refresh();

        $this->assertTrue(FlintQuestion::isRetired($stale));
        $this->assertNotNull(FlintQuestion::retiredAt($stale));
        $this->assertFalse(FlintQuestion::isOpen($stale));
    }

    #[Test]
    public function a_question_inside_the_horizon_stays_open(): void
    {
        $recent = $this->createQuestion(Carbon::now()->subDays(FlintQuestion::RETIREMENT_DAYS - 1));

        FlintQuestion::retireStale($this->user);

        $recent->refresh();

        $this->assertFalse(FlintQuestion::isRetired($recent));
        $this->assertTrue(FlintQuestion::isOpen($recent));
    }

    #[Test]
    public function an_answered_question_is_never_retired(): void
    {
        $answered = $this->createQuestion(Carbon::now()->subDays(30));
        app(FlintQuestionAnswerer::class)->record($answered, 'Deliberate push.');

        FlintQuestion::retireStale($this->user);

        $answered->refresh();

        $this->assertNull(FlintQuestion::retiredAt($answered));
        $this->assertFalse(FlintQuestion::isRetired($answered));
        $this->assertFalse(FlintQuestion::isOpen($answered));
    }

    #[Test]
    public function retirement_does_not_reset_on_a_later_sweep(): void
    {
        $stale = $this->createQuestion(Carbon::now()->subDays(30));

        FlintQuestion::retireStale($this->user);
        $stale->refresh();
        $firstStamp = FlintQuestion::retiredAt($stale);

        Carbon::setTestNow(Carbon::now()->addDay());
        FlintQuestion::retireStale($this->user);
        $stale->refresh();

        $this->assertEquals($firstStamp, FlintQuestion::retiredAt($stale));

        Carbon::setTestNow();
    }

    #[Test]
    public function another_users_questions_are_left_alone(): void
    {
        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        $theirs = $this->createQuestion(Carbon::now()->subDays(30), $otherIntegration);

        FlintQuestion::retireStale($this->user);

        $theirs->refresh();

        $this->assertNull(FlintQuestion::retiredAt($theirs));
    }

    #[Test]
    public function a_retired_question_is_presented_as_retired_and_still_answerable(): void
    {
        $stale = $this->createQuestion(Carbon::now()->subDays(30));
        FlintQuestion::retireStale($this->user);
        $stale->refresh();

        $presented = FlintBlockPresenter::collection(collect([$stale]))[0];

        $this->assertTrue($presented['retired']);
        $this->assertNotNull($presented['retired_at']);
        $this->assertFalse($presented['answered']);

        app(FlintQuestionAnswerer::class)->record($stale->refresh(), 'Late, but true.');

        $presented = FlintBlockPresenter::collection(collect([$stale->refresh()]))[0];

        $this->assertTrue($presented['answered']);
        $this->assertSame('Late, but true.', $presented['answer']);
        // Retirement described a question waiting on a reply. One arrived.
        $this->assertFalse($presented['retired']);
    }

    private function createQuestion(Carbon $askedAt, ?Integration $integration = null): Block
    {
        $event = Event::factory()->create([
            'integration_id' => ($integration ?? $this->integration)->id,
            'service' => 'flint',
            'domain' => 'knowledge',
            'action' => 'had_summary',
            'time' => $askedAt,
            'event_metadata' => ['period' => 'morning', 'title' => 'Morning Digest'],
        ]);

        return $event->createBlock([
            'block_type' => FlintQuestion::BLOCK_TYPE,
            'title' => 'Training through low readiness',
            'time' => $askedAt,
            'metadata' => [
                'question' => 'Was that a deliberate push?',
                'topic' => 'health',
                'priority' => 'medium',
                'answer' => null,
                'answer_note' => null,
                'answered_at' => null,
            ],
        ]);
    }
}
