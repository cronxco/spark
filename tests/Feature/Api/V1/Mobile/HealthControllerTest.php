<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Jobs\Data\AppleHealth\AppleHealthMetricData;
use App\Jobs\Data\AppleHealth\AppleHealthWorkoutData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function samples_requires_authentication(): void
    {
        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$this->stepSample('a')]])
            ->assertStatus(401);
    }

    #[Test]
    public function samples_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$this->stepSample('a')]])
            ->assertStatus(403);
    }

    #[Test]
    public function samples_rejects_empty_payload(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => []])
            ->assertStatus(422);
    }

    #[Test]
    public function samples_accepts_new_samples_and_dispatches_metric_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $samples = [];
        for ($day = 1; $day <= 20; $day++) {
            $samples[] = $this->stepSample('sample-' . $day, sprintf('2026-04-%02dT08:00:00Z', $day));
        }

        $response = $this->postJson('/api/v1/mobile/health/samples', ['samples' => $samples])
            ->assertOk();

        $results = $response->json('results');
        $this->assertCount(20, $results);
        foreach ($results as $row) {
            $this->assertEquals('accepted', $row['status']);
        }

        Queue::assertPushed(AppleHealthMetricData::class, fn ($job) => count($this->rawData($job)['data']) === 20);

        // The batch is the phone's sync; the day summary reads freshness from it.
        $this->assertNotNull(
            Integration::where('user_id', $this->user->id)
                ->where('service', 'apple_health')
                ->where('instance_type', 'metrics')
                ->value('last_successful_update_at')
        );
    }

    #[Test]
    public function samples_reports_duplicates_on_replay(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // The reading on record was taken at 08:00; replaying it changes nothing.
        $this->seedStepDay('2026-04-19', 1234, '2026-04-19T08:00:00+00:00');

        $response = $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$this->stepSample('dup-1')]])
            ->assertOk();

        $this->assertEquals('duplicate', $response->json('results.0.status'));
        Queue::assertNotPushed(AppleHealthMetricData::class);
    }

    #[Test]
    public function samples_replace_a_days_total_with_a_later_reading(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // 1,234 steps by 08:00 are on record; by 20:00 the phone has 8,064.
        $this->seedStepDay('2026-04-19', 1234, '2026-04-19T08:00:00+00:00');
        $later = $this->stepSample('steps-2026-04-19', '2026-04-19T00:00:00Z', ['as_of' => '2026-04-19T20:00:00+00:00']);
        $later['value'] = 8064;

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$later]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'updated');

        Queue::assertPushed(AppleHealthMetricData::class, function ($job) {
            $point = $this->rawData($job)['data'][0];

            return $point['qty'] === 8064
                && $point['date'] === '2026-04-19'
                && $point['as_of'] === '2026-04-19T20:00:00+00:00';
        });
    }

    #[Test]
    public function samples_replace_a_day_recorded_before_readings_carried_as_of(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->seedStepDay('2026-04-19', 1234, null);

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$this->stepSample('steps')]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'updated');

        Queue::assertPushed(AppleHealthMetricData::class);
    }

    #[Test]
    public function samples_keep_the_latest_reading_for_a_day_within_a_batch(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $noon = $this->stepSample('noon', '2026-04-19T00:00:00Z', ['as_of' => '2026-04-19T12:00:00Z']);
        $noon['value'] = 5000;
        $morning = $this->stepSample('morning', '2026-04-19T00:00:00Z', ['as_of' => '2026-04-19T08:00:00Z']);
        $morning['value'] = 2000;
        $evening = $this->stepSample('evening', '2026-04-19T00:00:00Z', ['as_of' => '2026-04-19T20:00:00Z']);
        $evening['value'] = 9000;

        $response = $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$noon, $morning, $evening]])
            ->assertOk();

        $this->assertSame(['duplicate', 'duplicate', 'accepted'], array_column($response->json('results'), 'status'));

        Queue::assertPushed(AppleHealthMetricData::class, function ($job) {
            $data = $this->rawData($job)['data'];

            return count($data) === 1 && $data[0]['qty'] === 9000;
        });
    }

    #[Test]
    public function samples_file_a_reading_under_the_clients_local_day(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // Local midnight in London (BST) is 23:00 UTC the day before.
        $sample = $this->stepSample('steps', '2026-04-18T23:00:00Z', ['date' => '2026-04-19']);

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$sample]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');

        Queue::assertPushed(AppleHealthMetricData::class, fn ($job) => $this->rawData($job)['data'][0]['date'] === '2026-04-19');
    }

    #[Test]
    public function samples_accepts_workout_and_dispatches_workout_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $workout = [
            'external_id' => 'workout-1',
            'type' => 'HKWorkoutType',
            'start' => '2026-04-19T08:00:00Z',
            'end' => '2026-04-19T08:30:00Z',
            'value' => 5.2,
            'unit' => 'km',
            'metadata' => ['name' => 'Running', 'duration' => 1800],
        ];

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$workout]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');

        Queue::assertPushed(AppleHealthWorkoutData::class);

        // A workout-only batch syncs the workouts instance, not the metrics
        // one whose step and exercise totals it says nothing about.
        $instances = Integration::where('user_id', $this->user->id)
            ->where('service', 'apple_health')
            ->pluck('last_successful_update_at', 'instance_type');
        $this->assertNotNull($instances['workouts']);
        $this->assertNull($instances['metrics']);
    }

    #[Test]
    public function samples_rejects_unknown_type(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $sample = [
            'external_id' => 'x',
            'type' => 'NotAHealthKitType',
            'start' => '2026-04-19T08:00:00Z',
        ];

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => [$sample]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected');
    }

    protected function stepSample(string $id, string $date = '2026-04-19T08:00:00Z', ?array $metadata = null): array
    {
        return array_filter([
            'external_id' => $id,
            'type' => 'HKQuantityTypeIdentifierStepCount',
            'start' => $date,
            'end' => $date,
            'value' => 1234,
            'unit' => 'count',
            'source' => 'iPhone',
            'metadata' => $metadata,
        ], fn ($value) => $value !== null);
    }

    protected function seedStepDay(string $date, int $steps, ?string $asOf): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'apple_health',
            'instance_type' => 'metrics',
        ]);

        $raw = ['date' => $date, 'qty' => $steps];
        if ($asOf !== null) {
            $raw['as_of'] = $asOf;
        }

        Event::factory()->create([
            'integration_id' => $integration->id,
            'source_id' => 'apple_metric_step_count_' . $date,
            'service' => 'apple_health',
            'domain' => 'health',
            'action' => 'had_step_count',
            'time' => $date . ' 00:00:00',
            'value' => $steps,
            'event_metadata' => ['metric' => 'step_count', 'raw' => $raw],
        ]);
    }

    protected function rawData(AppleHealthMetricData $job): array
    {
        return (fn () => $this->rawData)->call($job);
    }
}
