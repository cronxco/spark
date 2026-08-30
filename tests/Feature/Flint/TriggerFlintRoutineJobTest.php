<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintRoutineJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TriggerFlintRoutineJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'services.flint_routine.topics_url' => 'https://routine.example.test/topics',
            'services.flint_routine.reading_list_url' => 'https://routine.example.test/reading',
            'services.flint_routine.news_roundup_url' => null,
            'services.flint_routine.secret' => 'shh',
        ]);
    }

    private function runJob(string $routine = 'topics'): void
    {
        (new TriggerFlintRoutineJob($this->user, $routine, '2026-06-14', 'America/New_York'))->handle();
    }

    #[Test]
    public function posts_to_the_routine_webhook_with_a_signed_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('topics');

        Http::assertSent(fn ($request) => $request->url() === 'https://routine.example.test/topics'
            && $request->hasHeader('Authorization', 'Bearer shh')
            && $request['routine'] === 'topics'
            && $request['local_date'] === '2026-06-14'
            && $request['timezone'] === 'America/New_York'
            && $request['user_id'] === (string) $this->user->id
            && $request['idempotency_key'] === TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'topics'));

        $this->assertTrue(Cache::has(
            TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'topics')
        ));
    }

    #[Test]
    public function each_routine_posts_to_its_own_webhook(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('reading_list');

        Http::assertSent(fn ($request) => $request->url() === 'https://routine.example.test/reading'
            && $request['routine'] === 'reading_list');
    }

    #[Test]
    public function it_does_not_fire_twice_for_the_same_local_day(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('topics');
        $this->runJob('topics');

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_routine_without_a_configured_webhook_is_a_no_op(): void
    {
        Http::fake();

        $this->runJob('news_roundup');

        Http::assertNothingSent();
        $this->assertFalse(Cache::has(
            TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'news_roundup')
        ));
    }

    #[Test]
    public function an_unknown_routine_is_a_no_op(): void
    {
        Http::fake();

        $this->runJob('not_a_routine');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_clears_the_marker_so_a_failed_call_can_be_retried(): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

        try {
            $this->runJob('topics');
            $this->fail('Expected the failed webhook call to throw.');
        } catch (RequestException) {
            // expected
        }

        $this->assertFalse(Cache::has(
            TriggerFlintRoutineJob::markerKey($this->user->id, '2026-06-14', 'topics')
        ));
    }
}
