<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TriggerFlintDigestRoutineJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'services.flint_routine.url' => 'https://routine.example.test/hook',
            'services.flint_routine.secret' => 'shh',
        ]);
    }

    #[Test]
    public function posts_to_the_routine_webhook_with_signed_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('evening');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://routine.example.test/hook'
                && $request->hasHeader('Authorization', 'Bearer shh')
                && $request['period'] === 'evening'
                && $request['local_date'] === '2026-06-14'
                && $request['timezone'] === 'America/New_York'
                && $request['trigger_reason'] === 'scheduled'
                && $request['user_id'] === (string) $this->user->id
                && $request['idempotency_key'] === TriggerFlintDigestRoutineJob::markerKey(
                    $this->user->id,
                    '2026-06-14',
                    'evening',
                );
        });

        $this->assertTrue(Cache::has(
            TriggerFlintDigestRoutineJob::markerKey($this->user->id, '2026-06-14', 'evening')
        ));
    }

    #[Test]
    public function is_idempotent_when_marker_already_set(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        Cache::put(
            TriggerFlintDigestRoutineJob::markerKey($this->user->id, '2026-06-14', 'evening'),
            true,
            3600
        );

        $this->runJob('evening');

        Http::assertNothingSent();
    }

    #[Test]
    public function skips_when_digest_already_exists(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
        ]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => '2026-06-14 12:00:00', // within the NY local day
            'event_metadata' => ['period' => 'evening'],
        ]);

        $this->runJob('evening');

        Http::assertNothingSent();
    }

    #[Test]
    public function no_op_when_webhook_url_missing(): void
    {
        config(['services.flint_routine.url' => null]);
        Http::fake();

        $this->runJob('evening');

        Http::assertNothingSent();
        $this->assertFalse(Cache::has(
            TriggerFlintDigestRoutineJob::markerKey($this->user->id, '2026-06-14', 'evening')
        ));
    }

    #[Test]
    public function releases_the_marker_when_the_webhook_fails(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 500)]);

        try {
            $this->runJob('morning');
            $this->fail('Expected the webhook failure to be thrown.');
        } catch (\Illuminate\Http\Client\RequestException) {
            // Expected: the job should be retried by the queue.
        }

        $this->assertFalse(Cache::has(
            TriggerFlintDigestRoutineJob::markerKey($this->user->id, '2026-06-14', 'morning')
        ));
    }

    private function runJob(string $period = 'evening', string $reason = 'scheduled'): void
    {
        (new TriggerFlintDigestRoutineJob(
            $this->user,
            $period,
            '2026-06-14',
            'America/New_York',
            $reason,
        ))->handle();
    }
}
