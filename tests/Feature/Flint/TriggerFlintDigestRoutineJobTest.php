<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\TriggerFlintDigestRoutineJob;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
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
            'services.flint_routine.routines.digest.url' => 'https://routine.example.test/hook',
            'services.flint_routine.secret' => 'shh',
        ]);
    }

    #[Test]
    public function posts_to_the_routine_webhook_with_signed_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->runJob('evening');

        Http::assertSent(function ($request) {
            $payload = $this->firedPayload($request);

            return $request->url() === 'https://routine.example.test/hook'
                && $request->hasHeader('Authorization', 'Bearer shh')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request->hasHeader('anthropic-beta', 'experimental-cc-routine-2026-04-01')
                && $payload['period'] === 'evening'
                && $payload['local_date'] === '2026-06-14'
                && $payload['timezone'] === 'America/New_York'
                && $payload['trigger_reason'] === 'scheduled'
                && is_string($payload['run_token'] ?? null)
                && $payload['user_id'] === (string) $this->user->id
                && $payload['idempotency_key'] === TriggerFlintDigestRoutineJob::markerKey(
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
            'event_metadata' => [
                'period' => 'evening',
                'routine' => 'digest',
                'trigger_source' => 'scheduled',
            ],
        ]);

        $this->runJob('evening');

        Http::assertNothingSent();
    }

    #[Test]
    public function no_op_when_webhook_url_missing(): void
    {
        config(['services.flint_routine.routines.digest.url' => null]);
        Http::fake();

        $this->runJob('evening');

        Http::assertNothingSent();
        $this->assertFalse(Cache::has(
            TriggerFlintDigestRoutineJob::markerKey($this->user->id, '2026-06-14', 'evening')
        ));
    }

    #[Test]
    public function releases_the_marker_only_after_the_webhook_run_terminally_fails(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 500)]);

        $job = new TriggerFlintDigestRoutineJob(
            $this->user,
            'morning',
            '2026-06-14',
            'America/New_York',
            'scheduled',
        );
        $exception = null;
        try {
            $job->handle();
            $this->fail('Expected the webhook failure to be thrown.');
        } catch (RequestException $caught) {
            $exception = $caught;
        }

        $this->assertTrue(Cache::has(
            TriggerFlintDigestRoutineJob::markerKey($this->user->id, '2026-06-14', 'morning')
        ));

        $job->failed($exception);

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

    /**
     * The trigger payload, dug back out of the extra turn the fire endpoint
     * appends to the run. A Routine does not read the request body as
     * instructions, so `text` is the only channel into the session.
     *
     * @return array<string, mixed>
     */
    private function firedPayload(mixed $request): array
    {
        $this->assertSame(['text'], array_keys($request->data()));
        $text = $request['text'];
        $json = substr($text, (int) strpos($text, '{'));

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
